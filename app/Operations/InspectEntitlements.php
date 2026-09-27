<?php

declare(strict_types=1);

namespace App\Operations;

use App\Billing\BillingFacts;
use App\Entitlements\RefreshReceipts;
use App\Entitlements\ResolveAllowance;
use App\Models\Organization;
use App\Models\WebhookEvent;
use App\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;

/**
 * Receipts and the request share a whole-second timestamp and no sequence, so events in
 * the same second are listed together rather than guessing a cause.
 */
final readonly class InspectEntitlements
{
    public function __construct(
        private TenantContext $tenant,
        private OwnerLocator $owners,
        private ResolveAllowance $allowances,
        private LocalResolver $resolver,
        private NativeStateStore $states,
        private RefreshReceipts $receipts,
        private PriceCatalog $catalog,
        private BillingFacts $billing,
    ) {}

    /**
     * @return array{
     *     plan: string|null,
     *     features: list<array{feature: string, allowance: bool|int|null, source: string, usage: int|null}>,
     *     refresh: array{status: string, observed_at: string|null, last_success_at: string|null, last_error: string|null, stale: bool, catalog_matches: bool},
     *     trigger: array{kind: string, events: list<array{stripe_event_id: string, type: string|null, outcome: string|null, applied_at: string|null, primary: bool}>}
     * }
     */
    public function for(Organization $organization): array
    {
        return $this->tenant->runFor($organization, function () use ($organization): array {
            $owner = $this->owners->reference($organization);
            $at = Date::now()->toDateTimeImmutable();
            $state = $this->states->state($owner);

            return [
                'plan' => $this->billing->currentPlan($organization)?->name,
                'features' => $this->features($owner, $at),
                'refresh' => $this->refresh($state, $at),
                'trigger' => $this->trigger($owner, $state),
            ];
        });
    }

    /**
     * @return list<array{feature: string, allowance: bool|int|null, source: string, usage: int|null}>
     */
    private function features(OwnerReference $owner, DateTimeImmutable $at): array
    {
        $features = [];

        foreach ($this->resolver->catalogFeatures() as $feature) {
            $answer = $this->allowances->explain($owner, $feature, $at);

            $features[] = [
                'feature' => $feature,
                'allowance' => $answer['value'],
                'source' => $answer['source']->value,
                'usage' => $this->usage($owner, $feature, $at),
            ];
        }

        return $features;
    }

    private function usage(OwnerReference $owner, string $feature, DateTimeImmutable $at): ?int
    {
        if ($this->resolver->booleanFeature($feature)) {
            return null;
        }

        try {
            return $this->resolver->usageStore()->usage($owner, $feature, $at);
        } catch (ReadFailure) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array{status: string, observed_at: string|null, last_success_at: string|null, last_error: string|null, stale: bool, catalog_matches: bool}
     */
    private function refresh(?array $state, DateTimeImmutable $at): array
    {
        if ($state === null) {
            return ['status' => 'never', 'observed_at' => null, 'last_success_at' => null, 'last_error' => null, 'stale' => false, 'catalog_matches' => false];
        }

        $observedAt = $this->timestamp($state['observed_at'] ?? null);
        $maxStaleAge = config()->integer('cashier-entitlements.freshness.max_stale_age', 0);
        $lastError = $state['last_error'] ?? null;

        return [
            'status' => match (true) {
                is_string($lastError) => 'failing',
                $state['requested_sequence'] > $state['completed_sequence'] => 'pending',
                default => 'current',
            },
            'observed_at' => $observedAt === null ? null : Date::createFromTimestamp($observedAt)->toIso8601String(),
            'last_success_at' => ($success = $this->timestamp($state['last_success_at'] ?? null)) === null ? null : Date::createFromTimestamp($success)->toIso8601String(),
            'last_error' => is_string($lastError) ? $lastError : null,
            'stale' => $observedAt !== null && $maxStaleAge > 0 && $at->getTimestamp() - $observedAt > $maxStaleAge,
            'catalog_matches' => ($state['catalog_version'] ?? null) === $this->catalog->version,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array{kind: string, events: list<array{stripe_event_id: string, type: string|null, outcome: string|null, applied_at: string|null, primary: bool}>}
     */
    private function trigger(OwnerReference $owner, ?array $state): array
    {
        if ($state === null) {
            return ['kind' => 'never', 'events' => []];
        }

        if ($state['requested_sequence'] > $state['completed_sequence']) {
            return ['kind' => 'pending', 'events' => []];
        }

        $requestedAt = $this->timestamp($state['requested_at'] ?? null);
        $eventIds = $requestedAt === null ? [] : $this->receipts->receivedAt($owner, $requestedAt);

        if ($eventIds === []) {
            return ['kind' => 'none', 'events' => []];
        }

        $recorded = WebhookEvent::query()
            ->whereIn('stripe_event_id', $eventIds)
            ->get()
            ->keyBy('stripe_event_id');

        $events = collect($eventIds)
            ->map(fn (string $eventId): array => [
                'stripe_event_id' => $eventId,
                'type' => $recorded->get($eventId)?->type,
                'outcome' => $recorded->get($eventId)?->outcome->value,
                'applied_at' => $recorded->get($eventId)?->applied_at?->toIso8601String(),
                'created' => $recorded->get($eventId)->stripe_created_at ?? PHP_INT_MAX,
            ])
            ->sortBy('created')
            ->values();

        $primary = $events->search(fn (array $event): bool => $event['applied_at'] !== null
            && is_string($event['type'])
            && str_starts_with($event['type'], 'customer.subscription.'));

        $listed = [];

        foreach ($events as $index => $event) {
            $listed[] = [
                'stripe_event_id' => $event['stripe_event_id'],
                'type' => $event['type'],
                'outcome' => $event['outcome'],
                'applied_at' => $event['applied_at'],
                'primary' => $index === $primary,
            ];
        }

        return ['kind' => 'events', 'events' => $listed];
    }

    private function timestamp(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
