<?php

declare(strict_types=1);

use App\Exceptions\TenantContextMissing;
use App\Models\Organization;
use App\Models\Subscription;
use App\Tenancy\ResolveTenantForRefresh;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Jobs\RefreshOwner;
use Impruthvi\CashierEntitlements\Reconciliation\DryRunReconciler;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Reconciliation\RefreshManager;
use Impruthvi\CashierEntitlements\Reconciliation\SweepManager;
use Laravel\Cashier\Cashier;

/**
 * The refresh reports a stage, not an exception: reaching the provider is
 * provider_unavailable, while failing earlier at the tenant-scoped relation is
 * provider_failed. The provider points at a closed port so nothing touches the network.
 */
$reachableStripeApiBaseUrl = Cashier::$apiBaseUrl;

beforeEach(function (): void {
    Cashier::$apiBaseUrl = 'http://127.0.0.1:1';
});

// Restored unconditionally: a skipped test must not leave the provider pointed at a
// closed port for later suites.
afterEach(function () use ($reachableStripeApiBaseUrl): void {
    Cashier::$apiBaseUrl = $reachableStripeApiBaseUrl;
});

/** @return array{0: Organization, 1: OwnerReference} */
function organizationAwaitingRefresh(): array
{
    [$organization] = organizationOwnedBySomeone();
    $organization->forceFill(['stripe_id' => 'cus_refresh_'.$organization->id])->save();

    $reference = organizationEntitlementOwner($organization);

    acrossEveryOwner(fn (): OwnerReference => resolve(RefreshManager::class)->request($organization, dispatch: false));

    return [$organization, $reference];
}

it('resolves the organization around a queued refresh', function (): void {
    [$organization, $reference] = organizationAwaitingRefresh();
    $resolved = null;
    $subscriptionsWereReadable = false;

    resolve(ResolveTenantForRefresh::class)->handle(
        new RefreshOwner($reference),
        function () use (&$resolved, &$subscriptionsWereReadable): void {
            $resolved = resolve(TenantContext::class)->id();
            $subscriptionsWereReadable = Subscription::query()->count() === 0;
        },
    );

    expect($resolved)->toBe($organization->id)
        ->and($subscriptionsWereReadable)->toBeTrue();
});

it('leaves work that is not an entitlement refresh alone', function (): void {
    $ran = false;

    resolve(ResolveTenantForRefresh::class)->handle(
        new stdClass(),
        function () use (&$ran): void {
            $ran = true;

            expect(resolve(TenantContext::class)->hasTenant())->toBeFalse();
        },
    );

    expect($ran)->toBeTrue();
});

it('carries the tenant far enough for a dispatched refresh to reach the provider', function (): void {
    [, $reference] = organizationAwaitingRefresh();

    expect(fn (): mixed => acrossEveryOwner(fn () => Bus::dispatch(new RefreshOwner($reference))))
        ->toThrow(ReadFailure::class, 'provider_unavailable');
});

it('stops at the tenant boundary when nothing resolved the organization', function (): void {
    [, $reference] = organizationAwaitingRefresh();

    expect(fn (): string => acrossEveryOwner(fn (): string => resolve(RefreshManager::class)->refresh($reference)))
        ->toThrow(ReadFailure::class, 'provider_failed')
        ->and(fn (): int => Subscription::query()->count())
        ->toThrow(TenantContextMissing::class);
});

it('applies for one owner from the console with the organization resolved', function (): void {
    [$organization] = organizationAwaitingRefresh();

    $exit = acrossEveryOwner(fn (): int => Artisan::call('entitlements:reconcile', [
        '--owner-type' => 'organization',
        '--owner' => (string) $organization->id,
        '--apply' => true,
        '--json' => true,
    ]));

    expect($exit)->toBe(2)
        ->and(Artisan::output())->toContain('provider_unavailable')
        ->and(Artisan::output())->not->toContain('diagnostic_failed');
});

it('reads one owner from the console with the organization resolved', function (): void {
    [$organization] = organizationAwaitingRefresh();

    $exit = acrossEveryOwner(fn (): int => Artisan::call('entitlements:reconcile', [
        '--owner-type' => 'organization',
        '--owner' => (string) $organization->id,
        '--json' => true,
    ]));

    expect($exit)->toBe(2)
        ->and(Artisan::output())->toContain('provider_unavailable')
        ->and(Artisan::output())->not->toContain('diagnostic_failed');
});

it('needs an organization for the console read at all', function (): void {
    [$organization] = organizationAwaitingRefresh();

    expect(fn (): array => resolve(DryRunReconciler::class)->run(
        $organization,
        resolve(PriceCatalog::class),
        Date::now()->toDateTimeImmutable(),
        'default',
    ))->toThrow(TenantContextMissing::class);
});

it('refuses an audit that names no owner to resolve', function (): void {
    $exit = Artisan::call('entitlements:reconcile', [
        '--owner-type' => 'organization',
        '--all' => true,
        '--json' => true,
    ]);

    expect($exit)->toBe(2)
        ->and(Artisan::output())->toContain('owner_scope_required');
});

it('re-enqueues a backlog rather than refreshing it in place', function (): void {
    Queue::fake();

    [, $reference] = organizationAwaitingRefresh();

    expect(acrossEveryOwner(fn (): int => resolve(RefreshManager::class)->recover()))->toBe(1);

    Queue::assertPushed(
        RefreshOwner::class,
        fn (RefreshOwner $job): bool => $job->owner->equals($reference),
    );
});

it('sweeps a stale owner onto the queue rather than refreshing it in place', function (): void {
    Queue::fake();

    [$organization] = organizationOwnedBySomeone();
    $organization->forceFill(['stripe_id' => 'cus_sweep_'.$organization->id])->save();

    $swept = acrossEveryOwner(
        fn (): array => resolve(SweepManager::class)->run('organization', staleAfter: 1800),
    );

    expect($swept['requested'])->toBe(1)
        ->and($swept['complete'])->toBeTrue();

    Queue::assertPushed(RefreshOwner::class);
});
