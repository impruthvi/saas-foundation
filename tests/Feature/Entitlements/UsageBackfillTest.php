<?php

declare(strict_types=1);

use App\Entitlements\ResolveAllowance;
use App\Models\Organization;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;

function replayProjectUsageBackfill(): void
{
    $migration = 'database/migrations/2026_09_22_194645_backfill_project_usage_counters.php';

    Artisan::call('migrate:rollback', ['--path' => $migration, '--force' => true]);
    Artisan::call('migrate', ['--path' => $migration, '--force' => true]);
}

it('backfills lifetime usage and remaining allowance from existing projects', function (): void {
    $organization = Organization::factory()->create();
    $emptyOrganization = Organization::factory()->create();

    resolve(TenantContext::class)->runFor(
        $organization,
        fn () => Project::factory()->for($organization)->create(),
    );

    replayProjectUsageBackfill();

    $owner = resolve(OwnerLocator::class)->reference($organization);
    $emptyOwner = resolve(OwnerLocator::class)->reference($emptyOrganization);
    $at = Date::now()->toDateTimeImmutable();
    $allowance = resolve(ResolveAllowance::class)->handle($owner, 'projects', $at);
    $usage = resolve(LocalResolver::class)->for($owner, $at)->usage('projects');

    expect($allowance)->toBe(2)
        ->and($usage)->toBe(1)
        ->and($allowance - $usage)->toBe(1)
        ->and(resolve(LocalResolver::class)->for($emptyOwner, $at)->usage('projects'))->toBe(0)
        ->and(DB::table('cashier_entitlement_usage_counters')->count())->toBe(2);
});

it('replaces the backfilled total instead of counting projects twice when replayed', function (): void {
    $organization = Organization::factory()->create();

    resolve(TenantContext::class)->runFor(
        $organization,
        fn () => Project::factory()->count(2)->for($organization)->create(),
    );

    replayProjectUsageBackfill();
    replayProjectUsageBackfill();

    $owner = resolve(OwnerLocator::class)->reference($organization);
    $at = Date::now()->toDateTimeImmutable();

    expect(resolve(LocalResolver::class)->for($owner, $at)->usage('projects'))->toBe(2)
        ->and(DB::table('cashier_entitlement_usage_counters')->count())->toBeOne();
});
