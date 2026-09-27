<?php

declare(strict_types=1);

namespace App\Operations;

use App\Billing\BillingFacts;
use App\Models\Organization;
use App\Models\SubscriptionEventWatermark;
use App\Models\WebhookEvent;
use App\Tenancy\TenantContext;

/**
 * Cashier keeps only the current row, so the history is the webhook log for the
 * organization's Stripe customer.
 */
final readonly class SubscriptionTimeline
{
    public function __construct(
        private TenantContext $tenant,
        private BillingFacts $billing,
    ) {}

    /**
     * @return array{state: string, plan: string|null, stripe_status: string|null, ends_at: string|null}
     */
    public function current(Organization $organization): array
    {
        return $this->tenant->runFor($organization, function () use ($organization): array {
            $subscription = $this->billing->currentSubscription($organization);

            return [
                'state' => $this->billing->state($organization),
                'plan' => $this->billing->currentPlan($organization)?->name,
                'stripe_status' => $subscription?->stripe_status,
                'ends_at' => $subscription?->ends_at?->toIso8601String(),
            ];
        });
    }

    /**
     * By Stripe's clock, not arrival order.
     *
     * @return array{rows: list<array{id: int, stripe_event_id: string|null, type: string|null, outcome: string, outcome_reason: string|null, applied_at: string|null, deliveries: int, stripe_created_at: int|null, wrote_current_state: bool}>, total: int}
     */
    public function events(Organization $organization, int $page = 1, int $perPage = 25): array
    {
        if ($organization->stripe_id === null) {
            return ['rows' => [], 'total' => 0];
        }

        $watermarks = $this->watermarks($organization);

        $events = WebhookEvent::query()
            ->where('stripe_customer_id', $organization->stripe_id)
            ->latest('stripe_created_at')
            ->orderByDesc('id')
            ->paginate($perPage, page: $page);

        return [
            'rows' => array_values(array_map(fn (WebhookEvent $event): array => $this->present($event, $watermarks), $events->items())),
            'total' => $events->total(),
        ];
    }

    /**
     * The Stripe event that wrote the subscription as it stands now, whatever has asked
     * for an entitlement refresh since.
     *
     * @return array{stripe_event_id: string|null, type: string|null, applied_at: string|null}|null
     */
    public function currentStateEvent(Organization $organization): ?array
    {
        if ($organization->stripe_id === null) {
            return null;
        }

        $watermarks = $this->watermarks($organization);

        if ($watermarks === []) {
            return null;
        }

        $event = WebhookEvent::query()
            ->where('stripe_customer_id', $organization->stripe_id)
            ->whereNotNull('applied_at')
            ->whereIn('stripe_object_id', array_keys($watermarks))
            ->latest('stripe_created_at')
            ->orderByDesc('id')
            ->get()
            ->first(fn (WebhookEvent $event): bool => $this->wroteCurrentState($event, $watermarks));

        return $event instanceof WebhookEvent ? [
            'stripe_event_id' => $event->stripe_event_id,
            'type' => $event->type,
            'applied_at' => $event->applied_at?->toIso8601String(),
        ] : null;
    }

    /**
     * @return array<string, int>
     */
    private function watermarks(Organization $organization): array
    {
        return $this->tenant->runFor(
            $organization,
            fn (): array => SubscriptionEventWatermark::query()->pluck('event_created_at', 'stripe_id')->all(),
        );
    }

    /**
     * @param  array<string, int>  $watermarks
     */
    private function wroteCurrentState(WebhookEvent $event, array $watermarks): bool
    {
        return $event->applied_at !== null
            && $event->stripe_object_id !== null
            && ($watermarks[$event->stripe_object_id] ?? null) === $event->stripe_created_at;
    }

    /**
     * @param  array<string, int>  $watermarks
     * @return array{id: int, stripe_event_id: string|null, type: string|null, outcome: string, outcome_reason: string|null, applied_at: string|null, deliveries: int, stripe_created_at: int|null, wrote_current_state: bool}
     */
    private function present(WebhookEvent $event, array $watermarks): array
    {
        return [
            'id' => $event->id,
            'stripe_event_id' => $event->stripe_event_id,
            'type' => $event->type,
            'outcome' => $event->outcome->value,
            'outcome_reason' => $event->outcome_reason,
            'applied_at' => $event->applied_at?->toIso8601String(),
            'deliveries' => $event->deliveries,
            'stripe_created_at' => $event->stripe_created_at,
            'wrote_current_state' => $this->wroteCurrentState($event, $watermarks),
        ];
    }
}
