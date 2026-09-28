<?php

declare(strict_types=1);

use App\Billing\BillingFacts;
use App\Billing\PlanCatalog;
use App\Models\Organization;
use App\Models\Subscription;
use App\Tenancy\TenantContext;

function subscriptionForBillingFacts(
    Organization $organization,
    string $status = 'active',
    ?string $priceId = null,
    ?DateTimeInterface $endsAt = null,
): Subscription {
    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Subscription => Subscription::query()->create([
            'type' => 'default',
            'stripe_id' => 'sub_facts_'.$organization->id,
            'stripe_status' => $status,
            'stripe_price' => $priceId,
            'quantity' => 1,
            'ends_at' => $endsAt,
        ]),
    );
}

it('loads every relation before asking Cashier for the current subscription', function (): void {
    $organization = Organization::factory()->create();
    $subscription = subscriptionForBillingFacts($organization);
    $proPrice = resolve(PlanCatalog::class)->findPlan('pro')?->prices[0];

    $subscription->items()->create([
        'stripe_id' => 'si_facts_'.$organization->id,
        'stripe_product' => 'prod_pro',
        'stripe_price' => $proPrice?->id,
        'quantity' => 1,
    ]);

    $organization = Organization::query()->findOrFail($organization->id);

    expect($organization->relationLoaded('subscriptions'))->toBeFalse();

    resolve(TenantContext::class)->runFor($organization, function () use ($organization, $subscription, $proPrice): void {
        $facts = resolve(BillingFacts::class);
        $current = $facts->currentSubscription($organization);

        expect($current?->is($subscription))->toBeTrue()
            ->and($organization->relationLoaded('subscriptions'))->toBeTrue()
            ->and($current?->relationLoaded('items'))->toBeTrue()
            ->and($facts->currentPrice($organization))->toBe($proPrice)
            ->and($facts->currentPlan($organization)?->key)->toBe('pro');
    });
});

it('renders each billing state and treats an ended subscription as none', function (
    ?string $status,
    ?int $endsAtOffset,
    string $expectedState,
    bool $hasOpenSubscription,
): void {
    $organization = Organization::factory()->create();

    if ($status !== null) {
        $priceId = resolve(PlanCatalog::class)->findPlan('pro')?->prices[0]->id;

        subscriptionForBillingFacts(
            $organization,
            $status,
            priceId: $priceId,
            endsAt: $endsAtOffset === null ? null : now()->addDays($endsAtOffset),
        );
    }

    resolve(TenantContext::class)->runFor($organization, function () use ($organization, $expectedState, $hasOpenSubscription): void {
        $facts = resolve(BillingFacts::class);

        expect($facts->state($organization))->toBe($expectedState)
            ->and($facts->hasOpenSubscription($organization))->toBe($hasOpenSubscription);
    });
})->with([
    'none' => [null, null, BillingFacts::STATE_NONE, false],
    'active' => ['active', null, BillingFacts::STATE_ACTIVE, true],
    'grace period' => ['active', 7, BillingFacts::STATE_GRACE_PERIOD, true],
    'past due' => ['past_due', null, BillingFacts::STATE_PAST_DUE, true],
    'unpaid' => ['unpaid', null, BillingFacts::STATE_INACTIVE, true],
    'incomplete' => ['incomplete', null, BillingFacts::STATE_INACTIVE, true],
    'ended' => ['canceled', -7, BillingFacts::STATE_NONE, false],
]);

it('keeps an unknown provider price from becoming an application plan', function (): void {
    $organization = Organization::factory()->create();
    subscriptionForBillingFacts($organization, priceId: 'price_unknown');

    resolve(TenantContext::class)->runFor($organization, function () use ($organization): void {
        $facts = resolve(BillingFacts::class);

        expect($facts->state($organization))->toBe(BillingFacts::STATE_ACTIVE)
            ->and($facts->currentPrice($organization))->toBeNull()
            ->and($facts->currentPlan($organization))->toBeNull();
    });
});
