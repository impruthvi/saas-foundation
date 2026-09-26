<?php

declare(strict_types=1);

use App\Enums\WebhookOutcome;
use App\Models\Organization;
use App\Models\WebhookEvent;
use App\Operations\InspectEntitlements;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;

/**
 * The projects row of an inspection.
 *
 * @param  array<string, mixed>  $inspection
 * @return array<string, mixed>
 */
function projectsRow(array $inspection): array
{
    return collect($inspection['features'])->firstWhere('feature', 'projects');
}

/**
 * Land a refresh that several webhook events asked for in the same second.
 *
 * @param  list<string>  $eventIds
 */
function refreshRequestedBy(Organization $organization, array $eventIds, DateTimeImmutable $at): void
{
    $owner = organizationEntitlementOwner($organization);
    $store = resolve(NativeStateStore::class);

    foreach ($eventIds as $eventId) {
        $store->request($owner, $at, $eventId);
    }

    $store->complete(
        $store->claim($owner, $at),
        BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'),
        resolve(PriceCatalog::class)->version,
        $at,
        $at,
    );
}

it('shows the Free floor for an organization that was never refreshed', function (): void {
    $organization = Organization::factory()->create();

    $inspection = resolve(InspectEntitlements::class)->for($organization);

    expect(projectsRow($inspection))->toBe(['feature' => 'projects', 'allowance' => 2, 'source' => 'floor', 'usage' => 0])
        ->and($inspection['refresh']['status'])->toBe('never')
        ->and($inspection['trigger'])->toBe(['kind' => 'never', 'events' => []]);
});

it('credits the package for a paid allowance and counts usage against it', function (): void {
    $organization = Organization::factory()->create();
    $owner = organizationEntitlementOwner($organization);
    applyAllowanceDecision($owner, BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'), Date::now()->toDateTimeImmutable());
    resolve(LocalResolver::class)->usageStore()->record($owner, 'projects', 3, 'inspector-usage');

    $inspection = resolve(InspectEntitlements::class)->for($organization);

    expect(projectsRow($inspection))->toBe(['feature' => 'projects', 'allowance' => 10, 'source' => 'package', 'usage' => 3])
        ->and($inspection['refresh'])->status->toBe('current')->stale->toBeFalse()->catalog_matches->toBeTrue()
        ->and($inspection['trigger'])->toBe(['kind' => 'none', 'events' => []]);
});

it('says the answer is stale and falls to the floor when the observation is too old', function (): void {
    $organization = Organization::factory()->create();
    applyAllowanceDecision(
        organizationEntitlementOwner($organization),
        BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'),
        Date::now()->subHours(2)->toDateTimeImmutable(),
    );

    $inspection = resolve(InspectEntitlements::class)->for($organization);

    expect(projectsRow($inspection))->source->toBe('floor')->allowance->toBe(2)
        ->and($inspection['refresh']['stale'])->toBeTrue();
});

it('reports a refresh still waiting to run', function (): void {
    $organization = Organization::factory()->create();
    resolve(NativeStateStore::class)->request(organizationEntitlementOwner($organization), Date::now()->toDateTimeImmutable());

    $inspection = resolve(InspectEntitlements::class)->for($organization);

    expect($inspection['refresh']['status'])->toBe('pending')
        ->and($inspection['trigger']['kind'])->toBe('pending');
});

it('reports a refresh that failed', function (): void {
    $organization = Organization::factory()->create();
    $owner = organizationEntitlementOwner($organization);
    $store = resolve(NativeStateStore::class);
    $at = Date::now()->toDateTimeImmutable();
    $store->request($owner, $at);
    $store->fail($store->claim($owner, $at), 'stripe_unreachable', $at);

    $inspection = resolve(InspectEntitlements::class)->for($organization);

    expect($inspection['refresh'])->status->toBe('failing')->last_error->toBe('stripe_unreachable');
});

it('lists every event that asked for the refresh in the same second, the subscription event first', function (): void {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_acme']);
    WebhookEvent::factory()->create(['stripe_event_id' => 'evt_invoice', 'type' => 'invoice.payment_succeeded', 'stripe_customer_id' => 'cus_acme', 'stripe_created_at' => 1_001]);
    WebhookEvent::factory()->create(['stripe_event_id' => 'evt_subscription', 'type' => 'customer.subscription.created', 'stripe_customer_id' => 'cus_acme', 'stripe_created_at' => 1_000]);
    refreshRequestedBy($organization, ['evt_invoice', 'evt_subscription', 'evt_unrecorded'], Date::now()->toDateTimeImmutable());

    $events = resolve(InspectEntitlements::class)->for($organization)['trigger']['events'];

    expect(array_column($events, 'stripe_event_id'))->toBe(['evt_subscription', 'evt_invoice', 'evt_unrecorded'])
        ->and(array_column($events, 'primary'))->toBe([true, false, false])
        ->and($events[0]['outcome'])->toBe(WebhookOutcome::Applied->value)
        ->and($events[2]['type'])->toBeNull();
});

it('does not name an event from an earlier request', function (): void {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_acme']);
    refreshRequestedBy($organization, ['evt_earlier'], Date::now()->subMinute()->toDateTimeImmutable());
    applyAllowanceDecision(
        organizationEntitlementOwner($organization),
        BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'),
        Date::now()->toDateTimeImmutable(),
    );

    $inspection = resolve(InspectEntitlements::class)->for($organization);

    expect($inspection['trigger'])->toBe(['kind' => 'none', 'events' => []]);
});
