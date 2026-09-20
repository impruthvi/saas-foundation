<?php

declare(strict_types=1);

use App\Actions\StartBillingCheckout;
use App\Billing\PlanCatalog;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Laravel\Cashier\Checkout;
use Stripe\StripeClient;
use Tests\Support\FakeStripeClient;

it('reuses provider resources when checkout is retried', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $price = resolve(PlanCatalog::class)->findPlan('pro')?->prices[0];
    $stripe = new FakeStripeClient();
    app()->bind(StripeClient::class, fn (): StripeClient => $stripe);

    $start = (fn (Organization $current): Checkout => resolve(TenantContext::class)->runFor(
        $current,
        fn (): Checkout => resolve(StartBillingCheckout::class)->handle(
            $current,
            $price,
            'https://app.test/success',
            'https://app.test/cancel',
        ),
    ));

    expect($start($organization))->toBeInstanceOf(Checkout::class);

    // Reproduce the only local state a rollback could leave after Stripe has
    // already created the customer: no saved customer id on the organization.
    $organization->forceFill(['stripe_id' => null])->save();
    $organization->unsetRelation('subscriptions');

    expect($start($organization))->toBeInstanceOf(Checkout::class)
        ->and($stripe->customerRequests)->toHaveCount(2)
        ->and($stripe->uniqueCustomerCount())->toBe(1)
        ->and($stripe->customerRequests[0]['options']['idempotency_key'])
        ->toBe($stripe->customerRequests[1]['options']['idempotency_key'])
        ->and($stripe->checkoutRequests)->toHaveCount(2)
        ->and($stripe->uniqueCheckoutCount())->toBe(1)
        ->and($stripe->checkoutRequests[0]['options']['idempotency_key'])
        ->toBe($stripe->checkoutRequests[1]['options']['idempotency_key']);

    $this->travel(1)->hour();
    $start($organization);

    expect($stripe->customerRequests)->toHaveCount(2)
        ->and($stripe->uniqueCustomerCount())->toBe(1)
        ->and($stripe->checkoutRequests)->toHaveCount(3)
        ->and($stripe->uniqueCheckoutCount())->toBe(2)
        ->and($stripe->checkoutRequests[2]['options']['idempotency_key'])
        ->not->toBe($stripe->checkoutRequests[1]['options']['idempotency_key']);
});
