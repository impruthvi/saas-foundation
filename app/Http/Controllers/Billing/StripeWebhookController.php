<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Exceptions\CrossTenantAccess;
use App\Exceptions\TenantContextMissing;
use App\Models\FailedWebhookEvent;
use App\Models\Organization;
use App\Models\SubscriptionEventWatermark;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe's events, applied on behalf of the organization they concern.
 *
 * Cashier's handlers write subscription rows, and those rows are tenant-scoped,
 * so the organization has to be resolved before the parent runs. It is recovered
 * from the Stripe customer in the payload, which is safe because organizations
 * are bounded by membership rather than by the tenant scope.
 *
 * Delivery is at least once and unordered, so a subscription event is applied
 * only when it is at least as recent as the newest one already applied for that
 * subscription. An event that cannot be placed, and one that has been
 * superseded, are both kept rather than retried. Other failures still return an
 * error so Stripe can deliver them again.
 */
final class StripeWebhookController extends WebhookController
{
    /**
     * Events whose subject is the customer itself, and which therefore carry the
     * customer in `id` rather than in `customer`.
     *
     * @var list<string>
     */
    private const array CUSTOMER_SUBJECT_EVENTS = [
        'customer.updated',
        'customer.deleted',
    ];

    /**
     * Events that write the subscription row, and are therefore order-sensitive.
     *
     * @var list<string>
     */
    private const array SUBSCRIPTION_SUBJECT_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    public function __construct(private readonly TenantContext $tenantContext)
    {
        parent::__construct();
    }

    public function __invoke(Request $request): Response
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), true) ?: [];

        $customerId = $this->customerIdFor($payload);

        if ($customerId === null) {
            return $this->forwardToCashier($request);
        }

        $organization = Cashier::findBillable($customerId);

        if (! $organization instanceof Organization) {
            return $this->retain(
                $payload,
                'OrganizationNotFound',
                "No organization is linked to Stripe customer [{$customerId}].",
            );
        }

        try {
            return $this->tenantContext->runFor(
                $organization,
                fn (): Response => $this->hasBeenSuperseded($payload)
                    ? $this->discard($payload)
                    : $this->forwardToCashier($request),
            );
        } catch (TenantContextMissing|CrossTenantAccess $exception) {
            return $this->retain(
                $payload,
                class_basename($exception),
                $exception->getMessage(),
            );
        }
    }

    private function forwardToCashier(Request $request): Response
    {
        $response = parent::handleWebhook($request);

        return $response instanceof Response ? $response : new Response;
    }

    /**
     * Stripe puts the customer in different places depending on the event.
     *
     * Events about a customer carry it as the object's own `id`; events about a
     * subscription, an invoice or a payment method carry it in `customer`.
     * Reading only one of the two leaves those events running with no tenant.
     *
     * @param  array<string, mixed>  $payload
     */
    private function customerIdFor(array $payload): ?string
    {
        $object = data_get($payload, 'data.object');

        if (! is_array($object)) {
            return null;
        }

        $key = in_array($payload['type'] ?? null, self::CUSTOMER_SUBJECT_EVENTS, true)
            ? 'id'
            : 'customer';

        $customerId = $object[$key] ?? null;

        return is_string($customerId) ? $customerId : null;
    }

    /**
     * Claim this delivery, reporting whether a newer one already beat it here.
     *
     * The comparison and the claim happen under one lock, so two deliveries
     * handled at once cannot both read the same watermark and both apply.
     *
     * Two deliveries are deliberately let through. An event carrying the same
     * timestamp as the watermark applies, because Stripe can emit two events in
     * the same second and because a redelivery of a failed event carries the
     * timestamp it already wrote. So does an event with no usable timestamp:
     * applying one out of order is recoverable by a later event, and dropping a
     * legitimate update is not.
     *
     * @param  array<string, mixed>  $payload
     */
    private function hasBeenSuperseded(array $payload): bool
    {
        if (! in_array($payload['type'] ?? null, self::SUBSCRIPTION_SUBJECT_EVENTS, true)) {
            return false;
        }

        $subscriptionId = data_get($payload, 'data.object.id');
        $createdAt = $payload['created'] ?? null;

        if (! is_string($subscriptionId) || ! is_int($createdAt)) {
            return false;
        }

        return DB::transaction(function () use ($subscriptionId, $createdAt): bool {
            $watermark = SubscriptionEventWatermark::query()
                ->where('stripe_id', $subscriptionId)
                ->lockForUpdate()
                ->first();

            if ($watermark === null) {
                SubscriptionEventWatermark::query()->create([
                    'stripe_id' => $subscriptionId,
                    'event_created_at' => $createdAt,
                ]);

                return false;
            }

            if ($watermark->event_created_at > $createdAt) {
                return true;
            }

            $watermark->update(['event_created_at' => $createdAt]);

            return false;
        });
    }

    /**
     * Acknowledge an event this application cannot apply, and keep it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function retain(array $payload, string $reason, string $message): Response
    {
        $this->keep($payload, $reason, $message);

        Log::warning('Retained a Stripe webhook this application could not place.', [
            'stripe_event_id' => is_string($payload['id'] ?? null) ? $payload['id'] : null,
            'reason' => $reason,
        ]);

        return new Response('Webhook retained.', Response::HTTP_OK);
    }

    /**
     * Acknowledge an event a newer one has already overtaken, and keep it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function discard(array $payload): Response
    {
        $this->keep(
            $payload,
            'SupersededDelivery',
            'A more recent event for this subscription had already been applied.',
        );

        return new Response('Webhook superseded.', Response::HTTP_OK);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function keep(array $payload, string $reason, string $message): void
    {
        FailedWebhookEvent::query()->create([
            'stripe_event_id' => is_string($payload['id'] ?? null) ? $payload['id'] : null,
            'type' => is_string($payload['type'] ?? null) ? $payload['type'] : null,
            'stripe_customer_id' => $this->customerIdFor($payload),
            'payload' => $payload,
            'reason' => $reason,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}
