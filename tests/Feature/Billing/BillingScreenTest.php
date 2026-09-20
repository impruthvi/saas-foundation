<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Billing\BillingFacts;
use App\Billing\PlanCatalog;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;

function subscriptionForBillingScreen(
    Organization $organization,
    string $status,
    ?DateTimeInterface $endsAt = null,
): Subscription {
    $priceId = resolve(PlanCatalog::class)->findPlan('pro')?->prices[0]->id;

    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Subscription => Subscription::query()->create([
            'type' => 'default',
            'stripe_id' => 'sub_screen_'.$organization->id,
            'stripe_status' => $status,
            'stripe_price' => $priceId,
            'quantity' => 1,
            'ends_at' => $endsAt,
        ]),
    );
}

it('offers the configured catalog when there is no subscription', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->get(route('organizations.billing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('billing/Index')
            ->where('subscription', null)
            ->where('canManageBilling', true)
            ->has('plans', 2)
            ->where('plans.0.key', 'free')
            ->where('plans.1.key', 'pro')
            ->missing('organization.stripe_id')
            ->missing('organization.pm_last_four'));
});

it('renders each open subscription state from billing facts', function (
    string $stripeStatus,
    ?int $endsAtOffset,
    string $expectedState,
): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    subscriptionForBillingScreen(
        $organization,
        $stripeStatus,
        $endsAtOffset === null ? null : now()->addDays($endsAtOffset),
    );

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->get(route('organizations.billing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('subscription.state', $expectedState)
            ->where('subscription.plan', ['key' => 'pro', 'name' => 'Pro'])
            ->where('subscription.price.interval', 'month')
            ->where('subscription.price.currency', 'usd')
            ->where('subscription.price.amount', 2000)
            ->missing('subscription.stripe_id')
            ->missing('subscription.stripe_status'));
})->with([
    'active' => ['active', null, BillingFacts::STATE_ACTIVE],
    'grace period' => ['active', 7, BillingFacts::STATE_GRACE_PERIOD],
    'past due' => ['past_due', null, BillingFacts::STATE_PAST_DUE],
]);

it('renders an ended subscription as none', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    subscriptionForBillingScreen($organization, 'canceled', now()->subDay());

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->get(route('organizations.billing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('subscription', null)
            ->has('plans', 2));
});

it('shows a member the screen without billing controls', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $member);

    $this->actingAs($member)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->get(route('organizations.billing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('billing/Index')
            ->where('canManageBilling', false));
});
