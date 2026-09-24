<?php

declare(strict_types=1);

use App\Billing\PlanCatalog;
use App\Entitlements\ResolveAllowance;
use App\Models\Organization;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;

function arrangeAllowanceState(OwnerReference $owner, string $state, DateTimeImmutable $at): void
{
    match ($state) {
        'brand_new' => null,
        'no_subscription' => applyAllowanceDecision(
            $owner,
            BillingDecision::denied('no_eligible_subscription'),
            $at,
        ),
        'active' => applyAllowanceDecision(
            $owner,
            BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'),
            $at,
        ),
        'grace_period' => applyAllowanceDecision(
            $owner,
            BillingDecision::allowed('mapped', $at->add(new DateInterval('P7D')), ['projects' => 10], 'pro'),
            $at,
        ),
        'ended' => applyAllowanceDecision(
            $owner,
            BillingDecision::allowed('mapped', $at->sub(new DateInterval('PT1S')), ['projects' => 10], 'pro'),
            $at,
        ),
        'stale' => applyAllowanceDecision(
            $owner,
            BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'),
            $at->sub(new DateInterval('PT1H')),
        ),
        'catalog_mismatch' => applyAllowanceDecision(
            $owner,
            BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'),
            $at,
            'v0-obsolete',
        ),
        default => throw new LogicException("Unknown allowance state [{$state}]."),
    };
}

it('uses the Free-plan floor whenever the package has no current answer', function (string $state): void {
    $owner = organizationEntitlementOwner(Organization::factory()->create());
    $at = Date::now()->toDateTimeImmutable();

    arrangeAllowanceState($owner, $state, $at);

    expect(resolve(ResolveAllowance::class)->handle($owner, 'projects', $at))->toBe(2);
})->with([
    'brand new' => ['brand_new'],
    'no subscription' => ['no_subscription'],
    'ended' => ['ended'],
    'stale' => ['stale'],
    'catalog mismatch' => ['catalog_mismatch'],
]);

it('keeps the paid allowance while access is active or on its grace period', function (string $state): void {
    $owner = organizationEntitlementOwner(Organization::factory()->create());
    $at = Date::now()->toDateTimeImmutable();

    arrangeAllowanceState($owner, $state, $at);

    expect(resolve(ResolveAllowance::class)->handle($owner, 'projects', $at))->toBe(10);
})->with([
    'active' => ['active'],
    'grace period' => ['grace_period'],
]);

it('raises an explicit zero to the floor and preserves unlimited access', function (?int $allowance, ?int $expected): void {
    $owner = organizationEntitlementOwner(Organization::factory()->create());
    $at = Date::now()->toDateTimeImmutable();

    applyAllowanceDecision(
        $owner,
        BillingDecision::allowed('mapped', allowances: ['projects' => $allowance], planKey: 'pro'),
        $at,
    );

    expect(resolve(ResolveAllowance::class)->handle($owner, 'projects', $at))->toBe($expected);
})->with([
    'explicit zero' => [0, 2],
    'unlimited' => [null, null],
]);

it('keeps every configured projects allowance at or above the Free-plan floor', function (): void {
    $plans = resolve(PlanCatalog::class);
    $free = $plans->findPlan('free');
    $floor = $free?->prices[0]->allowances['projects'] ?? null;

    expect($floor)->toBe(2);

    foreach ($plans->plans() as $plan) {
        foreach ($plan->prices as $price) {
            $allowance = $price->allowances['projects'] ?? null;

            expect($allowance === null || $allowance >= $floor)
                ->toBeTrue("Billing price [{$price->id}] declares projects below the Free-plan floor.");
        }
    }
});
