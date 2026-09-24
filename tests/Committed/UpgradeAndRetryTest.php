<?php

declare(strict_types=1);

use App\Actions\CreateProject;
use App\Billing\PlanCatalog;
use App\Entitlements\ResolveAllowance;
use App\Models\Organization;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Usage\LimitExceeded;
use Tests\Support\FakeStripeApi;
use Tests\Support\StripeWebhook;

/**
 * The milestone's headline arc: refused on Free, subscribed, allowed.
 *
 * Every other test in M5 proves one link. This proves they are joined — that
 * the refusal is lifted by a real webhook landing and a real refresh reading
 * the provider, rather than by a decision a test wrote directly into the
 * store. It lives in the committed lane because the refresh refuses to run
 * inside a transaction.
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
    $proPrice = resolve(PlanCatalog::class)->findPlan('pro')?->prices[0]->id;

    // Free allows two, and the third is refused server-side.
    createProjectFor($organization, 'First project', 'upgrade-one');
    createProjectFor($organization, 'Second project', 'upgrade-two');

    expect(projectsAllowedNow($organization))->toBe(2)
        ->and(fn (): Project => createProjectFor($organization, 'Third project', 'upgrade-three'))
        ->toThrow(LimitExceeded::class);

    // Checkout completes at Stripe, which tells us so by webhook. The provider
    // now reports the subscription the refresh is about to read back.
    $this->stripe->withActiveSubscription('cus_upgrade', (string) $proPrice, 'sub_upgrade');

    acrossEveryOwner(fn () => StripeWebhook::post(StripeWebhook::subscriptionPayload(
        customerId: 'cus_upgrade',
        created: 1_000,
        subscriptionId: 'sub_upgrade',
        itemId: 'si_upgrade',
    ))->assertOk());

    expect(projectsAllowedNow($organization))->toBe(10)
        ->and($this->stripe->requested)->toContain('get /v1/subscriptions');

    // The same third project the Free plan refused.
    $third = createProjectFor($organization, 'Third project', 'upgrade-three');

    expect($third->name)->toBe('Third project')
        ->and(resolve(TenantContext::class)->runFor($organization, fn (): int => Project::query()->count()))
        ->toBe(3);
});

it('keeps the two projects the Free plan already allowed', function (): void {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_keep']);

    createProjectFor($organization, 'First project', 'keep-one');
    createProjectFor($organization, 'Second project', 'keep-two');

    $this->stripe->withActiveSubscription(
        'cus_keep',
        (string) resolve(PlanCatalog::class)->findPlan('pro')?->prices[0]->id,
        'sub_keep',
    );

    acrossEveryOwner(fn () => StripeWebhook::post(StripeWebhook::subscriptionPayload(
        customerId: 'cus_keep',
        created: 1_000,
        subscriptionId: 'sub_keep',
        itemId: 'si_keep',
    ))->assertOk());

    // Usage is a lifetime meter, so upgrading raises the ceiling without
    // forgiving what has already been spent: eight of ten remain, not ten.
    expect(projectsAllowedNow($organization))->toBe(10)
        ->and(resolve(TenantContext::class)->runFor($organization, fn (): int => Project::query()->count()))
        ->toBe(2);
});
