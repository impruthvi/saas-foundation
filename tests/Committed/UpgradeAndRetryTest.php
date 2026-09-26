<?php

declare(strict_types=1);

use App\Actions\CreateProject;
use App\Entitlements\ResolveAllowance;
use App\Models\Organization;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Usage\LimitExceeded;
use Tests\Support\FakeStripeApi;
use Tests\Support\StripeWebhook;

/**
 * In the committed lane because the refresh refuses to run inside a transaction.
 */
beforeEach(function (): void {
    config(['cashier.webhook.secret' => StripeWebhook::SECRET]);

    $this->stripe = FakeStripeApi::install();
});

afterEach(function (): void {
    FakeStripeApi::uninstall();
});

function projectsAllowedNow(Organization $organization): ?int
{
    return resolve(ResolveAllowance::class)->handle(
        organizationEntitlementOwner($organization),
        'projects',
        Date::now()->toDateTimeImmutable(),
    );
}

function createProjectFor(Organization $organization, string $name, string $token): Project
{
    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Project => resolve(CreateProject::class)->handle($organization, $name, $token),
    );
}

it('lifts the Free refusal once the subscription webhook has been refreshed', function (): void {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_upgrade']);

    createProjectFor($organization, 'First project', 'upgrade-one');
    createProjectFor($organization, 'Second project', 'upgrade-two');

    expect(projectsAllowedNow($organization))->toBe(2)
        ->and(fn (): Project => createProjectFor($organization, 'Third project', 'upgrade-three'))
        ->toThrow(LimitExceeded::class);

    StripeWebhook::reportSubscription($this->stripe, 'cus_upgrade', 'sub_upgrade', 'si_upgrade');

    expect(projectsAllowedNow($organization))->toBe(10)
        ->and($this->stripe->requested)->toContain('get /v1/subscriptions');

    $third = createProjectFor($organization, 'Third project', 'upgrade-three');

    expect($third->name)->toBe('Third project')
        ->and(resolve(TenantContext::class)->runFor($organization, fn (): int => Project::query()->count()))
        ->toBe(3);
});

it('keeps the two projects the Free plan already allowed', function (): void {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_keep']);

    createProjectFor($organization, 'First project', 'keep-one');
    createProjectFor($organization, 'Second project', 'keep-two');

    StripeWebhook::reportSubscription($this->stripe, 'cus_keep', 'sub_keep', 'si_keep');

    // Usage is a lifetime meter, so upgrading raises the ceiling without forgiving what
    // was spent.
    expect(projectsAllowedNow($organization))->toBe(10)
        ->and(resolve(TenantContext::class)->runFor($organization, fn (): int => Project::query()->count()))
        ->toBe(2);
});
