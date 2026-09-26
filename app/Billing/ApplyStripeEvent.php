<?php

declare(strict_types=1);

namespace App\Billing;

use App\Audit\AuditActor;
use App\Enums\AuditSource;
use App\Enums\WebhookOutcome;
use App\Exceptions\CrossTenantAccess;
use App\Exceptions\TenantContextMissing;
use App\Models\Organization;
use App\Models\SubscriptionEventWatermark;
use App\Models\WebhookEvent;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Throwable;

/**
 * Applies one Stripe event on behalf of the organization it concerns.
 *
 * Cashier's handlers write subscription rows, and those rows are tenant-scoped,
 * so the organization has to be resolved before a handler runs. It is recovered
 * from the Stripe customer in the payload, which is safe because organizations
 * are bounded by membership rather than by the tenant scope.
 *
 * Delivery is at least once and unordered, so a subscription event is applied
 * only when it is at least as recent as the newest one already applied for that
 * subscription. An event that cannot be placed, and one that has been
 * superseded, are both kept rather than applied. Other failures propagate so
 * the caller can report them.
 *
 * Every delivery is recorded in `webhook_events`, whatever became of it, so the
 * event behind an organization's current plan can always be named.
 *
 * The caller supplies how the event is applied, because Cashier's handlers are
 * reachable only through its webhook controller.
 */
final readonly class ApplyStripeEvent
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

    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @template TApplied
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(): TApplied  $apply
     * @return TApplied|WebhookOutcome
     */
    public function handle(array $payload, Closure $apply): mixed
    {
        return AuditActor::runAs(
            AuditActor::source(AuditSource::Stripe),
            fn (): mixed => $this->place($payload, $apply),
        );
    }

    /**
     * @template TApplied
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(): TApplied  $apply
     * @return TApplied|WebhookOutcome
     */
    private function place(array $payload, Closure $apply): mixed
    {
        $customerId = $this->customerIdFor($payload);

        if ($customerId === null) {
            return $this->applying($payload, $apply);
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
                fn (): mixed => $this->hasBeenSuperseded($payload)
                    ? $this->discard($payload)
                    : $this->applying($payload, $apply),
            );
        } catch (TenantContextMissing|CrossTenantAccess $exception) {
            return $this->retain(
                $payload,
                class_basename($exception),
                $exception->getMessage(),
            );
        }
    }

    /**
     * Apply the event and record how that went.
     *
     * A tenant failure is left for the caller to keep as unplaceable. Anything
     * else is recorded as errored before it propagates, so a delivery Stripe
     * will retry still leaves a trace of why it failed.
     *
     * @template TApplied
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(): TApplied  $apply
     * @return TApplied
     */
    private function applying(array $payload, Closure $apply): mixed
    {
        try {
            $applied = $apply();
        } catch (TenantContextMissing|CrossTenantAccess $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->record($payload, WebhookOutcome::Errored, class_basename($exception), $exception->getMessage());

            throw $exception;
        }

        $this->record($payload, WebhookOutcome::Applied);

        return $applied;
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
     * Keep an event this application cannot apply.
     *
     * @param  array<string, mixed>  $payload
     */
    private function retain(array $payload, string $reason, string $message): WebhookOutcome
    {
        $this->record($payload, WebhookOutcome::Unplaceable, $reason, $message);

        Log::warning('Retained a Stripe webhook this application could not place.', [
            'stripe_event_id' => is_string($payload['id'] ?? null) ? $payload['id'] : null,
            'reason' => $reason,
        ]);

        return WebhookOutcome::Unplaceable;
    }

    /**
     * Keep an event a newer one has already overtaken.
     *
     * @param  array<string, mixed>  $payload
     */
    private function discard(array $payload): WebhookOutcome
    {
        $this->record(
            $payload,
            WebhookOutcome::Superseded,
            'SupersededDelivery',
            'A more recent event for this subscription had already been applied.',
        );

        return WebhookOutcome::Superseded;
    }

    /**
     * Write this delivery onto the event's single row.
     *
     * A redelivery updates the row rather than adding one. `applied_at` is set
     * the first time the event applies and never cleared, so a late redelivery
     * that is superseded does not erase what the event already did.
     *
     * @param  array<string, mixed>  $payload
     */
    private function record(array $payload, WebhookOutcome $outcome, ?string $reason = null, ?string $message = null): void
    {
        $eventId = is_string($payload['id'] ?? null) ? $payload['id'] : null;
        $objectId = data_get($payload, 'data.object.id');
        $createdAt = $payload['created'] ?? null;
        $now = now();

        $event = $eventId === null
            ? new WebhookEvent
            : WebhookEvent::query()->firstOrNew(['stripe_event_id' => $eventId]);

        $event->fill([
            'type' => is_string($payload['type'] ?? null) ? $payload['type'] : null,
            'stripe_customer_id' => $this->customerIdFor($payload),
            'stripe_object_id' => is_string($objectId) ? $objectId : null,
            'stripe_created_at' => is_int($createdAt) ? $createdAt : null,
            'outcome' => $outcome,
            'outcome_reason' => $reason,
            'outcome_message' => $message,
            'payload' => $payload,
            'deliveries' => $event->exists ? $event->deliveries + 1 : 1,
            'last_received_at' => $now,
        ]);

        if (! $event->exists) {
            $event->first_received_at = $now;
        }

        if ($outcome === WebhookOutcome::Applied && $event->applied_at === null) {
            $event->applied_at = $now;
        }

        $event->save();
    }
}
