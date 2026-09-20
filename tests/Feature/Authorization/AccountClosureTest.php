<?php

declare(strict_types=1);

use App\Actions\CreateOrganization;
use App\Actions\DeleteUser;
use App\Exceptions\BillingMustBeResolved;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Inertia\Inertia;
use Spatie\Permission\PermissionRegistrar;

function subscriptionForAccountClosure(
    Organization $organization,
    ?DateTimeInterface $endsAt = null,
): Subscription {
    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Subscription => Subscription::query()->create([
            'type' => 'default',
            'stripe_id' => 'sub_account_closure_'.$organization->id,
            'stripe_status' => $endsAt instanceof DateTimeInterface
                && $endsAt->getTimestamp() < now()->getTimestamp() ? 'canceled' : 'active',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
            'ends_at' => $endsAt,
        ]),
    );
}

it('leaves team scoping on when closing an account fails part-way', function (): void {
    $user = User::factory()->create();
    $permissions = resolve(PermissionRegistrar::class);

    try {
        resolve(DeleteUser::class)->handle($user);
        $this->fail('The unscoped cross-team detach should have been refused by the query guard.');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toContain('model_has_roles');
    }

    expect($permissions->teams)->toBeTrue();
});

it('closes an account when the crossing is declared', function (): void {
    $user = User::factory()->create();

    whileClosingAnAccount(fn () => resolve(DeleteUser::class)->handle($user));

    expect(User::query()->find($user->id))->toBeNull()
        ->and(resolve(PermissionRegistrar::class)->teams)->toBeTrue();
});

it('refuses account closure while an owned organization has active billing', function (): void {
    $user = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($user, 'Acme', personal: true);
    subscriptionForAccountClosure($organization);

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('profile.edit'));

    expect(Inertia::getFlashed()['toast'] ?? null)->toBe([
        'type' => 'error',
        'message' => 'Cancel the subscription for [Acme] before deleting this account.',
    ])->and($user->fresh())->not->toBeNull()
        ->and(Organization::query()->find($organization->id))->not->toBeNull();

    $this->assertAuthenticatedAs($user);
});

it('refuses account closure during the subscription grace period and names its end date', function (): void {
    $user = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($user, 'Acme', personal: true);
    $endsAt = now()->addWeek()->startOfDay();
    subscriptionForAccountClosure($organization, $endsAt);

    expect(fn () => resolve(DeleteUser::class)->handle($user))
        ->toThrow(BillingMustBeResolved::class, "ends on [{$endsAt->format('F j, Y')}]")
        ->and($user->fresh())->not->toBeNull()
        ->and(Organization::query()->find($organization->id))->not->toBeNull();
});

it('allows account closure after the subscription has ended', function (): void {
    $user = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($user, 'Acme', personal: true);
    subscriptionForAccountClosure($organization, now()->subDay());

    whileClosingAnAccount(fn () => resolve(DeleteUser::class)->handle($user));

    expect(User::query()->find($user->id))->toBeNull()
        ->and(Organization::query()->find($organization->id))->toBeNull();
});
