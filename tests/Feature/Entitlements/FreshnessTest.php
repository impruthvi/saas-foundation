<?php

declare(strict_types=1);

use App\Entitlements\ResolveAllowance;
use App\Models\Organization;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Jobs\RefreshOwner;
use Impruthvi\CashierEntitlements\Reconciliation\SweepManager;

/**
 * Expiry at 3600 seconds decides when a paid allowance stops being trusted; the sweep
 * at 1800 requests a replacement first. These assert what each threshold does, not the
 * configured numbers.
 */
function allowanceObservedSecondsAgo(int $age, ?int $allowance = 10): ?int
{
    $owner = organizationEntitlementOwner(Organization::factory()->create());

    applyAllowanceDecision(
        $owner,
        BillingDecision::allowed('mapped', allowances: ['projects' => $allowance], planKey: 'pro'),
        now()->subSeconds($age)->toDateTimeImmutable(),
    );

    return resolve(ResolveAllowance::class)->handle($owner, 'projects', now()->toDateTimeImmutable());
}

it('trusts a paid allowance right up to the moment it expires', function (): void {
    expect(allowanceObservedSecondsAgo(3_599))->toBe(10);
});

it('falls to the free floor the second the observation expires', function (): void {
    // Not zero: an outage drops the organization to Free, never out of its account.
    expect(allowanceObservedSecondsAgo(3_600))->toBe(2);
});

it('asks for a replacement while the allowance it would replace is still good', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create(['stripe_id' => 'cus_sweepable']);
    $owner = organizationEntitlementOwner($organization);

    // Half the expiry: old enough for the sweep, young enough to still resolve.
    applyAllowanceDecision(
        $owner,
        BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'),
        now()->subSeconds(1_800)->toDateTimeImmutable(),
    );

    $swept = acrossEveryOwner(fn (): array => resolve(SweepManager::class)->run(
        config('cashier-entitlements.schedule.owner_type'),
        staleAfter: config('cashier-entitlements.schedule.stale_after'),
    ));

    expect($swept['requested'])->toBe(1)
        ->and(resolve(ResolveAllowance::class)->handle($owner, 'projects', now()->toDateTimeImmutable()))
        ->toBe(10);

    Queue::assertPushed(
        RefreshOwner::class,
        fn (RefreshOwner $job): bool => $job->owner->equals($owner),
    );
});

it('leaves an observation younger than the sweep threshold alone', function (): void {
    Queue::fake();

    applyAllowanceDecision(
        organizationEntitlementOwner(Organization::factory()->create(['stripe_id' => 'cus_fresh'])),
        BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'),
        now()->subSeconds(1_799)->toDateTimeImmutable(),
    );

    $swept = acrossEveryOwner(fn (): array => resolve(SweepManager::class)->run(
        config('cashier-entitlements.schedule.owner_type'),
        staleAfter: config('cashier-entitlements.schedule.stale_after'),
    ));

    expect($swept['requested'])->toBe(0);

    Queue::assertNothingPushed();
});

it('reports a stale observation to the doctor', function (): void {
    $owner = organizationEntitlementOwner(Organization::factory()->create(['stripe_id' => 'cus_stale']));

    applyAllowanceDecision(
        $owner,
        BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'),
        now()->subSeconds(7_200)->toDateTimeImmutable(),
    );

    $report = doctorReport();

    expect($report['state']['stale'])->toBe(1)
        ->and($report['state']['threshold'])->toBe(3_600)
        ->and($report['state']['owners'])->toBe(1);
});

it('withholds a clean bill of health until a sweep has actually run', function (): void {
    Queue::fake();

    // A scope nothing has ever swept cannot say whether it missed a notification, so
    // the doctor warns.
    expect(checkNamed(doctorReport(), 'account_sweep')['status'])->toBe('warn');

    acrossEveryOwner(fn (): array => resolve(SweepManager::class)->run(
        config('cashier-entitlements.schedule.owner_type'),
        staleAfter: config('cashier-entitlements.schedule.stale_after'),
    ));

    $report = doctorReport();

    expect(checkNamed($report, 'account_sweep')['status'])->toBe('ok')
        ->and($report['exit_code'])->toBe(0)
        ->and($report['state']['stale'])->toBe(0)
        ->and($report['errors'])->toBe([]);
});

/**
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function checkNamed(array $report, string $name): array
{
    foreach ($report['checks'] as $check) {
        if ($check['name'] === $name) {
            return $check;
        }
    }

    throw new LogicException("The doctor reported no check named [{$name}].");
}

/** @return array<string, mixed> */
function doctorReport(): array
{
    $exit = acrossEveryOwner(fn (): int => Artisan::call('entitlements:doctor', [
        '--owner-type' => config('cashier-entitlements.schedule.owner_type'),
        '--json' => true,
    ]));

    expect($exit)->toBeIn([0, 1]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('runs the scheduler under composer dev, so the sweep renews a paid allowance before it expires', function (): void {
    expect(array_column(DevCommands::commands(), 'command'))->toContain('php artisan schedule:work');
});
