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
 * The organization is recovered from the Stripe customer before Cashier's handlers
 * write tenant-scoped rows. Delivery is at-least-once and unordered, so a subscription
 * event applies only if it is at least as recent as the newest applied one.
 */
final readonly class ApplyStripeEvent
{
    /**
     * These carry the customer in `id` rather than in `customer`.
     *
     * @var list<string>
     */
    private const array CUSTOMER_SUBJECT_EVENTS = [
        'customer.updated',
        'customer.deleted',
    ];

    /**
     * Order-sensitive: these write the subscription row.
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
     * A tenant failure is left to the caller as unplaceable; anything else is recorded
     * as errored before it propagates.
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
     * Compare and claim under one lock so concurrent deliveries cannot both apply.
     * Equal and missing timestamps apply: Stripe emits several events per second, and
     * dropping a legitimate update is worse than applying one late.
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
     * `applied_at` is set once and never cleared, so a late superseded redelivery does
     * not erase what the event did.
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
