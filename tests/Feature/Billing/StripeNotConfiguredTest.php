<?php

declare(strict_types=1);

use App\Billing\PlanCatalog;
use App\Http\Middleware\ResolveTenantContext;
use Inertia\Testing\AssertableInertia;
use Stripe\StripeClient;
use Tests\Support\FakeStripeClient;

it('shows the billing screen as not configured and never calls Stripe', function (?string $secret): void {
    config(['cashier.secret' => $secret]);
    [$organization, $owner] = organizationOwnedBySomeone();
    $stripe = new FakeStripeClient();
    app()->bind(StripeClient::class, fn (): StripeClient => $stripe);

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->get(route('organizations.billing.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('stripe.configured', false)
            ->where('stripe.setupHint', null));

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->from(route('organizations.billing.index'))
        ->post(route('organizations.billing.checkout.store'), [
            'organization' => $organization->slug,
            'price' => resolve(PlanCatalog::class)->findPlan('pro')?->prices[0]->id,
        ])
        ->assertRedirect(route('organizations.billing.index'))
        ->assertSessionHasErrors(['billing' => 'Stripe is not configured for this application.']);

    expect($stripe->customerRequests)->toBeEmpty()
        ->and($stripe->checkoutRequests)->toBeEmpty();
})->with([
    'no secret' => null,
    'a publishable key in the secret slot' => 'pk_test_abc',
]);

it('names the setup command only in local development', function (): void {
    config(['cashier.secret' => null]);
    [$organization, $owner] = organizationOwnedBySomeone();
    $environment = app()->environment();
    app()->detectEnvironment(fn (): string => 'local');

    try {
        $this->actingAs($owner)
            ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
            ->get(route('organizations.billing.index'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('stripe.setupHint', 'Run php artisan saas:stripe sk_test_YOUR_KEY.'));
    } finally {
        app()->detectEnvironment(fn (): string => $environment);
    }
});

it('shows the billing screen as configured for a secret key', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->get(route('organizations.billing.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('stripe.configured', true));
});
