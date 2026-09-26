<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\CreateOrganization;
use App\Actions\StartBillingCheckout;
use App\Billing\PlanCatalog;
use App\Enums\OrganizationStatus;
use App\Exceptions\Billing\OrganizationNotBillable;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Tests\Support\FakeStripeClient;

function fakeStripeForCheckout(): FakeStripeClient
{
    $stripe = new FakeStripeClient();
    app()->bind(StripeClient::class, fn (): StripeClient => $stripe);

    return $stripe;
}

it('starts checkout for a catalog price and leaves the Inertia application', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $price = resolve(PlanCatalog::class)->findPlan('pro')?->prices[0];
    $stripe = fakeStripeForCheckout();

    $response = $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->withHeader('X-Inertia', 'true')
        ->post(route('organizations.billing.checkout.store'), [
            'organization' => $organization->slug,
            'price' => $price?->id,
        ]);

    $response->assertConflict()
        ->assertHeader('X-Inertia-Location', 'https://checkout.stripe.test/session/1');

    expect($organization->refresh()->stripe_id)->toBe('cus_test_1')
        ->and($stripe->customerRequests)->toHaveCount(1)
        ->and($stripe->customerRequests[0]['parameters'])->toMatchArray([
            'name' => $organization->name,
            'email' => $owner->email,
        ])
        ->and($stripe->customerRequests[0]['options']['idempotency_key'])
        ->toBe("billing:customer:organization:{$organization->id}")
        ->and($stripe->checkoutRequests)->toHaveCount(1)
        ->and($stripe->checkoutRequests[0]['parameters'])->toMatchArray([
            'customer' => 'cus_test_1',
            'line_items' => [['price' => $price?->id, 'quantity' => 1]],
            'mode' => 'subscription',
            'success_url' => route('organizations.billing.index', ['checkout' => 'success']),
            'cancel_url' => route('organizations.billing.index', ['checkout' => 'cancelled']),
        ])
        ->and($stripe->checkoutRequests[0]['parameters']['subscription_data']['metadata'])
        ->toMatchArray([
            'is_on_session_checkout' => 'true',
            'name' => 'default',
            'type' => 'default',
        ]);
});

it('validates the price against the application catalog', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $stripe = fakeStripeForCheckout();

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->from(route('organizations.billing.index'))
        ->post(route('organizations.billing.checkout.store'), [
            'organization' => $organization->slug,
            'price' => 'price_from_somewhere_else',
        ])
        ->assertRedirect(route('organizations.billing.index'))
        ->assertSessionHasErrors('price');

    expect($stripe->customerRequests)->toBeEmpty()
        ->and($stripe->checkoutRequests)->toBeEmpty();
});

it('refuses a stale tab after the active organization changes', function (): void {
    [$first, $owner] = organizationOwnedBySomeone('First');
    $second = resolve(CreateOrganization::class)->handle($owner, 'Second');
    $priceId = resolve(PlanCatalog::class)->findPlan('pro')?->prices[0]->id;
    $stripe = fakeStripeForCheckout();

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $second->id])
        ->post(route('organizations.billing.checkout.store'), [
            'organization' => $first->slug,
            'price' => $priceId,
        ])
        ->assertConflict()
        ->assertSeeText('This organization changed elsewhere.');

    expect($stripe->customerRequests)->toBeEmpty()
        ->and($stripe->checkoutRequests)->toBeEmpty();
});

it('refuses a member who cannot manage billing', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $member);
    $priceId = resolve(PlanCatalog::class)->findPlan('pro')?->prices[0]->id;
    $stripe = fakeStripeForCheckout();

    $this->actingAs($member)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->post(route('organizations.billing.checkout.store'), [
            'organization' => $organization->slug,
            'price' => $priceId,
        ])
        ->assertForbidden();

    expect($stripe->customerRequests)->toBeEmpty()
        ->and($stripe->checkoutRequests)->toBeEmpty();
});

it('surfaces an existing subscription as a named billing refusal', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $priceId = resolve(PlanCatalog::class)->findPlan('pro')?->prices[0]->id;
    $stripe = fakeStripeForCheckout();

    resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Subscription => Subscription::query()->create([
            'type' => 'default',
            'stripe_id' => 'sub_existing',
            'stripe_status' => 'active',
            'stripe_price' => $priceId,
            'quantity' => 1,
        ]),
    );

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->from(route('organizations.billing.index'))
        ->post(route('organizations.billing.checkout.store'), [
            'organization' => $organization->slug,
            'price' => $priceId,
        ])
        ->assertRedirect(route('organizations.billing.index'))
        ->assertSessionHasErrors([
            'billing' => "[{$organization->name}] already has an active subscription.",
        ]);

    expect($stripe->customerRequests)->toBeEmpty()
        ->and($stripe->checkoutRequests)->toBeEmpty();
});

it('refuses an organization that is not usable before contacting Stripe', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $organization->forceFill(['status' => OrganizationStatus::Archived])->save();
    $price = resolve(PlanCatalog::class)->findPlan('pro')?->prices[0];
    $stripe = fakeStripeForCheckout();

    expect(fn () => resolve(TenantContext::class)->runFor(
        $organization,
        fn () => resolve(StartBillingCheckout::class)->handle(
            $organization,
            $price,
            'https://app.test/success',
            'https://app.test/cancel',
        ),
    ))->toThrow(OrganizationNotBillable::class, 'is archived')
        ->and($stripe->customerRequests)->toBeEmpty()
        ->and($stripe->checkoutRequests)->toBeEmpty();
});

it('says the Stripe account has no price for the plan instead of reporting an outage', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $priceId = resolve(PlanCatalog::class)->findPlan('pro')?->prices[0]->id;
    $stripe = fakeStripeForCheckout();
    $stripe->checkoutFailure = InvalidRequestException::factory(
        "No such price: '{$priceId}'",
        stripeCode: 'resource_missing',
        stripeParam: 'line_items[0][price]',
    );

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->from(route('organizations.billing.index'))
        ->post(route('organizations.billing.checkout.store'), [
            'organization' => $organization->slug,
            'price' => $priceId,
        ])
        ->assertRedirect(route('organizations.billing.index'))
        ->assertSessionHasErrors([
            'billing' => 'This Stripe account has no price for this plan yet.',
        ]);
});

it('turns a Stripe outage into a recoverable billing error', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $priceId = resolve(PlanCatalog::class)->findPlan('pro')?->prices[0]->id;
    $stripe = fakeStripeForCheckout();
    $stripe->checkoutFailure = ApiConnectionException::factory('Network unavailable.');

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->from(route('organizations.billing.index'))
        ->post(route('organizations.billing.checkout.store'), [
            'organization' => $organization->slug,
            'price' => $priceId,
        ])
        ->assertRedirect(route('organizations.billing.index'))
        ->assertSessionHasErrors([
            'billing' => 'The payment provider is unavailable. Try again.',
        ]);

    expect($stripe->customerRequests)->toHaveCount(1)
        ->and($stripe->checkoutRequests)->toHaveCount(1);
});
