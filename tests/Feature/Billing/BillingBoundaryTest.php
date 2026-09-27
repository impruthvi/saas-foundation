<?php

declare(strict_types=1);

use App\Exceptions\CrossTenantAccess;
use App\Exceptions\TenantContextMissing;
use App\Models\Organization;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Tests\Support\TenantQueryGuard;

/**
 * The suite-wide query guard returns early on any SQL containing organization_id, which
 * every Cashier query carries, so the scope and both guards are exercised here
 * directly.
 */
function subscribe(Organization $organization, string $status = 'active'): Subscription
{
    return resolve(TenantContext::class)->runForId(
        $organization->id,
        fn (): Subscription => Subscription::query()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.$organization->id.'_'.$status,
            'stripe_status' => $status,
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]),
    );
}

it('stamps a new subscription with the resolved organization', function (): void {
    $organization = Organization::factory()->create();

    expect(subscribe($organization)->organization_id)->toBe($organization->id);
});

it('refuses to record a subscription with no organization resolved', function (): void {
    Subscription::query()->create([
        'type' => 'default',
        'stripe_id' => 'sub_nobody',
        'stripe_status' => 'active',
    ]);
})->throws(TenantContextMissing::class);

it('refuses to read a subscription with no organization resolved', function (): void {
    Subscription::query()->get();
})->throws(TenantContextMissing::class);

it('keeps one organization from reading another subscription', function (): void {
    [$first, $second] = [Organization::factory()->create(), Organization::factory()->create()];

    subscribe($first);
    subscribe($second);

    $visible = resolve(TenantContext::class)->runForId(
        $second->id,
        fn (): array => Subscription::query()->pluck('organization_id')->all(),
    );

    expect($visible)->toBe([$second->id]);
});

it('raises when a subscription arrives from another organization with the scope bypassed', function (): void {
    [$first, $second] = [Organization::factory()->create(), Organization::factory()->create()];

    $theirs = subscribe($first);

    resolve(TenantContext::class)->runForId($second->id, function () use ($theirs): void {
        TenantQueryGuard::allowUnscoped(
            fn () => Subscription::query()->withoutTenantScope()->whereKey($theirs->id)->first()
        );
    });
})->throws(CrossTenantAccess::class);

it('refuses to write through a subscription that belongs to another organization', function (): void {
    [$first, $second] = [Organization::factory()->create(), Organization::factory()->create()];

    $theirs = subscribe($first);

    $stale = TenantQueryGuard::allowUnscoped(
        fn (): ?Subscription => Subscription::query()->withoutTenantScope()->find($theirs->id)
    );

    try {
        resolve(TenantContext::class)->runForId($second->id, function () use ($stale): void {
            $stale->forceFill(['stripe_status' => 'canceled'])->save();
        });

        test()->fail("Writing another organization's subscription should have been refused.");
    } catch (CrossTenantAccess) {
        $reloaded = TenantQueryGuard::allowUnscoped(
            fn (): ?Subscription => Subscription::query()->withoutTenantScope()->find($theirs->id)
        );

        expect($reloaded?->stripe_status)->toBe('active');
    }
});

it('resolves the owner of a subscription through the organization', function (): void {
    $organization = Organization::factory()->create();

    $subscription = subscribe($organization);

    expect($subscription->owner()->getForeignKeyName())->toBe('organization_id');
});
