<?php

declare(strict_types=1);

use App\Actions\CreateProject;
use App\Billing\PlanCatalog;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Inertia\Testing\AssertableInertia as Assert;

function createProjectsFor(Organization $organization, int $count): void
{
    resolve(TenantContext::class)->runFor($organization, function () use ($organization, $count): void {
        $create = resolve(CreateProject::class);

        for ($number = 1; $number <= $count; $number++) {
            $create->handle($organization, "Project {$number}", "screen-request-{$number}");
        }
    });
}

function allowProjectsFor(Organization $organization, ?int $allowance): void
{
    applyAllowanceDecision(
        organizationEntitlementOwner($organization),
        BillingDecision::allowed('mapped', allowances: ['projects' => $allowance], planKey: 'pro'),
        Date::now()->toDateTimeImmutable(),
    );
}

function visitProjectsAs(User $user, Organization $organization): TestResponse
{
    return test()
        ->actingAs($user)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->get(route('projects.index'));
}

it('counts an untouched organization against the Free-plan floor', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();

    visitProjectsAs($owner, $organization)
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/Index')
            ->where('allowance.limit', 2)
            ->where('allowance.usage', 0)
            ->where('allowance.remaining', 2)
            ->where('canCreate', true)
            ->where('accessEndsAt', null)
            ->has('projects.data', 0));
});

it('leaves nothing remaining once the allowance is spent', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    createProjectsFor($organization, 2);

    visitProjectsAs($owner, $organization)
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('allowance.limit', 2)
            ->where('allowance.usage', 2)
            ->where('allowance.remaining', 0)
            ->where('allowance.upgradePlan', 'Pro')
            ->has('projects.data', 2));
});

it('reports the paid allowance while the subscription answers', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    allowProjectsFor($organization, 10);
    createProjectsFor($organization, 3);

    visitProjectsAs($owner, $organization)
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('allowance.limit', 10)
            ->where('allowance.usage', 3)
            ->where('allowance.remaining', 7)
            ->has('projects.data', 3));
});

it('keeps every project when a downgrade drops usage below the limit', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    allowProjectsFor($organization, 10);
    createProjectsFor($organization, 6);

    applyAllowanceDecision(
        organizationEntitlementOwner($organization),
        BillingDecision::denied('no_eligible_subscription'),
        Date::now()->toDateTimeImmutable(),
    );

    visitProjectsAs($owner, $organization)
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('allowance.limit', 2)
            ->where('allowance.usage', 6)
            ->where('allowance.remaining', 0)
            ->has('projects.data', 6));

    expect(resolve(TenantContext::class)->runFor(
        $organization,
        fn (): int => Project::query()->count(),
    ))->toBe(6);
});

it('reports unlimited access as no limit rather than as zero', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    allowProjectsFor($organization, null);
    createProjectsFor($organization, 3);

    visitProjectsAs($owner, $organization)
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('allowance.limit', null)
            ->where('allowance.remaining', null)
            ->where('allowance.usage', 3));
});

it('names the day a grace period ends', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $endsAt = now()->addDays(7);

    resolve(TenantContext::class)->runFor($organization, fn (): Subscription => Subscription::query()->create([
        'type' => 'default',
        'stripe_id' => 'sub_projects_screen',
        'stripe_status' => 'active',
        'stripe_price' => resolve(PlanCatalog::class)->findPlan('pro')?->prices[0]->id,
        'quantity' => 1,
        'ends_at' => $endsAt,
    ]));

    visitProjectsAs($owner, $organization)
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('accessEndsAt', $endsAt->toFormattedDateString()));
});

it('refuses the screen to somebody outside the organization', function (): void {
    [$organization] = organizationOwnedBySomeone();

    visitProjectsAs(User::factory()->create(), $organization)->assertForbidden();
});
