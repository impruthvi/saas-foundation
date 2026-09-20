<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Billing\PlanCatalog;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Stripe\StripeClient;
use Tests\Support\FakeStripeClient;

function fakeStripeForBillingBrowser(): FakeStripeClient
{
    $stripe = new FakeStripeClient();
    app()->bind(StripeClient::class, fn (): StripeClient => $stripe);

    return $stripe;
}

function subscriptionForBillingBrowser(Organization $organization): Subscription
{
    $priceId = resolve(PlanCatalog::class)->findPlan('pro')?->prices[0]->id;

    return resolve(TenantContext::class)->runFor($organization, function () use ($organization, $priceId): Subscription {
        $subscription = Subscription::query()->create([
            'type' => 'default',
            'stripe_id' => 'sub_browser_'.$organization->id,
            'stripe_status' => 'active',
            'stripe_price' => $priceId,
            'quantity' => 1,
        ]);

        $subscription->items()->create([
            'stripe_id' => 'si_browser_'.$organization->id,
            'stripe_product' => 'prod_pro',
            'stripe_price' => $priceId,
            'quantity' => 1,
        ]);

        return $subscription;
    });
}

it('opens billing from the navigation and leaves for Stripe Checkout', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $stripe = fakeStripeForBillingBrowser();

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);

    visit('/dashboard')
        ->click('Billing')
        ->assertPathIs('/organizations/billing')
        ->assertSee('Pro')
        ->press('Subscribe to Pro')
        ->assertPathIsNot('/organizations/billing');

    expect($stripe->checkoutRequests)->toHaveCount(1);
});

it('cancels and resumes the current subscription', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    subscriptionForBillingBrowser($organization);
    $stripe = fakeStripeForBillingBrowser();

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);

    $page = visit('/organizations/billing');

    $page->assertSee('Active')
        ->press('Cancel subscription')
        ->assertSee('Cancel this subscription?')
        ->click('[role="dialog"] button[type="submit"]')
        ->assertSee('Subscription ending')
        ->assertSee('Resume subscription')
        ->press('Resume subscription')
        ->assertSee('Active')
        ->assertNoJavaScriptErrors();

    expect($stripe->subscriptionRequests)->toHaveCount(2);
});

it('keeps billing controls from members', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $member);

    $this->actingAs($member)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);

    visit('/organizations/billing')
        ->assertSee('You can view billing details')
        ->assertDontSee('Subscribe to Pro')
        ->assertDontSee('Cancel subscription')
        ->assertDontSee('Resume subscription')
        ->assertNoJavaScriptErrors();
});
