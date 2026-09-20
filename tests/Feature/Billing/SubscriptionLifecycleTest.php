<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\CreateOrganization;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Stripe\Exception\ApiConnectionException;
use Stripe\StripeClient;
use Tests\Support\FakeStripeClient;

function fakeStripeForSubscriptionLifecycle(): FakeStripeClient
{
    $stripe = new FakeStripeClient();
    app()->bind(StripeClient::class, fn (): StripeClient => $stripe);

    return $stripe;
}

function subscriptionForLifecycle(
    Organization $organization,
    ?DateTimeInterface $endsAt = null,
): Subscription {
    return resolve(TenantContext::class)->runFor($organization, function () use ($organization, $endsAt): Subscription {
        $subscription = Subscription::query()->create([
            'type' => 'default',
            'stripe_id' => 'sub_lifecycle_'.$organization->id,
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
            'ends_at' => $endsAt,
        ]);

        $subscription->items()->create([
            'stripe_id' => 'si_lifecycle_'.$organization->id,
            'stripe_product' => 'prod_pro',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]);

        return $subscription;
    });
}

it('cancels an active subscription at the end of its current period', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $subscription = subscriptionForLifecycle($organization);
    $stripe = fakeStripeForSubscriptionLifecycle();
    $stripe->subscriptionPeriodEnd = now()->addMonth()->getTimestamp();

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->from(route('organizations.billing.index'))
        ->delete(route('organizations.billing.subscription.destroy'), [
            'organization' => $organization->slug,
        ])
        ->assertRedirect(route('organizations.billing.index'))
        ->assertSessionHasNoErrors();

    $subscription = resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Subscription => Subscription::query()->findOrFail($subscription->id),
    );

    expect($subscription->onGracePeriod())->toBeTrue()
        ->and($subscription->ends_at?->getTimestamp())->toBe($stripe->subscriptionPeriodEnd)
        ->and($stripe->subscriptionRequests)->toHaveCount(1)
        ->and($stripe->subscriptionRequests[0])->toMatchArray([
            'id' => $subscription->stripe_id,
            'parameters' => ['cancel_at_period_end' => true],
        ])
        ->and($stripe->subscriptionItemRequests)->toHaveCount(1)
        ->and($stripe->subscriptionItemRequests[0]['id'])->toBe('si_lifecycle_'.$organization->id);
});

it('resumes a subscription during its grace period', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $subscription = subscriptionForLifecycle($organization, now()->addWeek());
    $stripe = fakeStripeForSubscriptionLifecycle();

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->from(route('organizations.billing.index'))
        ->patch(route('organizations.billing.subscription.update'), [
            'organization' => $organization->slug,
        ])
        ->assertRedirect(route('organizations.billing.index'))
        ->assertSessionHasNoErrors();

    $subscription = resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Subscription => Subscription::query()->findOrFail($subscription->id),
    );

    expect($subscription->active())->toBeTrue()
        ->and($subscription->ends_at)->toBeNull()
        ->and($stripe->subscriptionRequests)->toHaveCount(1)
        ->and($stripe->subscriptionRequests[0])->toMatchArray([
            'id' => $subscription->stripe_id,
            'parameters' => [
                'cancel_at_period_end' => false,
                'trial_end' => 'now',
            ],
        ]);
});

it('refuses cancellation when there is no active subscription', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $stripe = fakeStripeForSubscriptionLifecycle();

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->from(route('organizations.billing.index'))
        ->delete(route('organizations.billing.subscription.destroy'), [
            'organization' => $organization->slug,
        ])
        ->assertRedirect(route('organizations.billing.index'))
        ->assertSessionHasErrors([
            'billing' => "[{$organization->name}] has no active subscription to cancel.",
        ]);

    expect($stripe->subscriptionRequests)->toBeEmpty();
});

it('refuses to resume a subscription outside its grace period', function (?int $endsAtOffset): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    subscriptionForLifecycle(
        $organization,
        $endsAtOffset === null ? null : now()->addDays($endsAtOffset),
    );
    $stripe = fakeStripeForSubscriptionLifecycle();

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->from(route('organizations.billing.index'))
        ->patch(route('organizations.billing.subscription.update'), [
            'organization' => $organization->slug,
        ])
        ->assertRedirect(route('organizations.billing.index'))
        ->assertSessionHasErrors([
            'billing' => "[{$organization->name}] does not have a cancelled subscription that can be resumed.",
        ]);

    expect($stripe->subscriptionRequests)->toBeEmpty();
})->with([
    'still active' => [null],
    'already ended' => [-1],
]);

it('refuses a member who cannot manage a subscription', function (string $method, string $routeName): void {
    [$organization] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $member);
    $stripe = fakeStripeForSubscriptionLifecycle();

    $this->actingAs($member)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->call($method, route($routeName), ['organization' => $organization->slug])
        ->assertForbidden();

    expect($stripe->subscriptionRequests)->toBeEmpty();
})->with([
    'cancel' => ['DELETE', 'organizations.billing.subscription.destroy'],
    'resume' => ['PATCH', 'organizations.billing.subscription.update'],
]);

it('refuses every subscription mutation from a stale organization tab', function (string $method, string $routeName): void {
    [$first, $owner] = organizationOwnedBySomeone('First');
    $second = resolve(CreateOrganization::class)->handle($owner, 'Second');
    $stripe = fakeStripeForSubscriptionLifecycle();

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $second->id])
        ->call($method, route($routeName), ['organization' => $first->slug])
        ->assertConflict()
        ->assertSeeText('This organization changed elsewhere.');

    expect($stripe->subscriptionRequests)->toBeEmpty();
})->with([
    'cancel' => ['DELETE', 'organizations.billing.subscription.destroy'],
    'resume' => ['PATCH', 'organizations.billing.subscription.update'],
]);

it('turns a provider outage into a recoverable subscription error', function (
    string $method,
    string $routeName,
    ?int $endsAtOffset,
): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    subscriptionForLifecycle(
        $organization,
        $endsAtOffset === null ? null : now()->addDays($endsAtOffset),
    );
    $stripe = fakeStripeForSubscriptionLifecycle();
    $stripe->subscriptionFailure = ApiConnectionException::factory('Network unavailable.');

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->from(route('organizations.billing.index'))
        ->call($method, route($routeName), ['organization' => $organization->slug])
        ->assertRedirect(route('organizations.billing.index'))
        ->assertSessionHasErrors([
            'billing' => 'The payment provider is unavailable. Try again.',
        ]);

    expect($stripe->subscriptionRequests)->toHaveCount(1);
})->with([
    'cancel' => ['DELETE', 'organizations.billing.subscription.destroy', null],
    'resume' => ['PATCH', 'organizations.billing.subscription.update', 7],
]);
