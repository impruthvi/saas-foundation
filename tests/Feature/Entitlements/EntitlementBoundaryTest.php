<?php

declare(strict_types=1);

use App\Actions\CreateProject;
use App\Entitlements\ResolveAllowance;
use App\Exceptions\CrossTenantAccess;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Organization;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Usage\LimitExceeded;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;

/**
 * The query guard can refuse a statement that narrows on nothing, but cannot prove a
 * hashed counter id belongs to the resolved organization; this file proves that half.
 */
function spendProjects(Organization $organization, int $count): void
{
    resolve(TenantContext::class)->runFor($organization, function () use ($organization, $count): void {
        $create = resolve(CreateProject::class);

        for ($number = 1; $number <= $count; $number++) {
            $create->handle($organization, "Project {$number}", "boundary-{$organization->id}-{$number}");
        }
    });
}

function projectsUsedBy(Organization $organization): int
{
    return resolve(LocalResolver::class)->usageStore()->usage(
        organizationEntitlementOwner($organization),
        'projects',
        Date::now()->toDateTimeImmutable(),
    );
}

function projectsAllowedTo(Organization $organization): ?int
{
    return resolve(ResolveAllowance::class)->handle(
        organizationEntitlementOwner($organization),
        'projects',
        Date::now()->toDateTimeImmutable(),
    );
}

it('keeps one organization from spending another allowance', function (): void {
    [$first] = organizationOwnedBySomeone('First');
    [$second] = organizationOwnedBySomeone('Second');

    spendProjects($first, 2);

    expect(projectsUsedBy($first))->toBe(2)
        ->and(projectsUsedBy($second))->toBe(0);

    spendProjects($second, 2);

    expect(projectsUsedBy($first))->toBe(2)
        ->and(projectsUsedBy($second))->toBe(2);
});

it('leaves a neighbour able to create after one organization reaches its limit', function (): void {
    [$first] = organizationOwnedBySomeone('First');
    [$second] = organizationOwnedBySomeone('Second');

    spendProjects($first, 2);

    expect(fn () => spendProjects($first, 3))->toThrow(LimitExceeded::class);

    spendProjects($second, 1);

    expect(projectsUsedBy($second))->toBe(1)
        ->and(resolve(TenantContext::class)->runFor($second, fn (): int => Project::query()->count()))->toBe(1);
});

it('keeps a paid allowance from reaching the organization beside it', function (): void {
    [$first] = organizationOwnedBySomeone('First');
    [$second] = organizationOwnedBySomeone('Second');

    applyAllowanceDecision(
        organizationEntitlementOwner($first),
        BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'),
        Date::now()->toDateTimeImmutable(),
    );

    expect(projectsAllowedTo($first))->toBe(10)
        ->and(projectsAllowedTo($second))->toBe(2);
});

it('refuses to spend a neighbour allowance even with their organization in hand', function (): void {
    [$first] = organizationOwnedBySomeone('First');
    [$second] = organizationOwnedBySomeone('Second');

    spendProjects($second, 1);

    expect(fn (): Project => resolve(TenantContext::class)->runFor(
        $second,
        fn (): Project => resolve(CreateProject::class)->handle($first, 'Crossed project', 'crossed'),
    ))->toThrow(CrossTenantAccess::class)
        ->and(projectsUsedBy($first))->toBe(0)
        ->and(projectsUsedBy($second))->toBe(1);
});

it('shows each organization only its own counter on the projects screen', function (): void {
    [$first] = organizationOwnedBySomeone('First');
    [$second, $secondOwner] = organizationOwnedBySomeone('Second');

    spendProjects($first, 2);
    spendProjects($second, 1);

    test()->actingAs($secondOwner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $second->id])
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('allowance.usage', 1)
            ->where('allowance.remaining', 1)
            ->has('projects.data', 1));
});

it('refuses a ledger read that narrows on no owner at all', function (): void {
    DB::table('cashier_entitlement_usage_counters')->count();
})->throws(RuntimeException::class, 'cashier_entitlement_usage_counters');
