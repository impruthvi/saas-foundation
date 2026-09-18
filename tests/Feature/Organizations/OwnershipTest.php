<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\CreateOrganization;
use App\Actions\DeleteUser;
use App\Actions\TransferOrganizationOwnership;
use App\Enums\MembershipRole;
use App\Exceptions\OwnershipTransferRequired;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Tests\Support\TenantQueryGuard;

/*
|--------------------------------------------------------------------------
| Ownership, transfer, and refusing to orphan
|--------------------------------------------------------------------------
|
| Ownership is one column (D23). Transfer exists because account deletion has
| to be able to name a remedy when it refuses (D25).
|
*/

it('makes the creating user the owner and a member', function (): void {
    $user = User::factory()->create();

    $organization = resolve(CreateOrganization::class)->handle($user, 'Acme');

    $membership = resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Membership => Membership::query()->where('user_id', $user->id)->sole()
    );

    expect($organization->owner_id)->toBe($user->id)
        ->and($organization->personal)->toBeFalse()
        ->and($membership->role)->toBe(MembershipRole::Admin);
});

it('moves ownership to an existing member and keeps the outgoing owner on', function (): void {
    $owner = User::factory()->create();
    $successor = User::factory()->create();

    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    resolve(AddOrganizationMember::class)->handle($organization, $successor);

    $organization = resolve(TransferOrganizationOwnership::class)->handle($organization, $successor);

    $roles = resolve(TenantContext::class)->runFor(
        $organization,
        fn (): array => Membership::query()->pluck('role', 'user_id')->all()
    );

    expect($organization->owner_id)->toBe($successor->id)
        ->and($roles[$successor->id])->toBe(MembershipRole::Admin)
        ->and($roles[$owner->id])->toBe(MembershipRole::Admin);
});

it('refuses to transfer to someone who is not a member', function (): void {
    $organization = resolve(CreateOrganization::class)->handle(User::factory()->create(), 'Acme');

    resolve(TransferOrganizationOwnership::class)->handle($organization, User::factory()->create());
})->throws(InvalidArgumentException::class, 'active member');

it('refuses to transfer a personal organization', function (): void {
    $user = User::factory()->create();
    $successor = User::factory()->create();

    $organization = resolve(CreateOrganization::class)->handle($user, 'Personal', personal: true);
    resolve(AddOrganizationMember::class)->handle($organization, $successor);

    resolve(TransferOrganizationOwnership::class)->handle($organization, $successor);
})->throws(InvalidArgumentException::class, 'personal organization cannot be transferred');

it('refuses to delete an owner who would orphan an organization', function (): void {
    $owner = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    resolve(AddOrganizationMember::class)->handle($organization, User::factory()->create());

    try {
        resolve(DeleteUser::class)->handle($owner);
        $this->fail('Deleting the sole owner of a shared organization should have been refused.');
    } catch (OwnershipTransferRequired $ownershipTransferRequired) {
        expect($ownershipTransferRequired->getMessage())->toContain('Acme')
            ->and(User::query()->find($owner->id))->not->toBeNull()
            ->and(Organization::query()->find($organization->id))->not->toBeNull();
    }
});

it('deletes the organizations a departing user alone owns', function (): void {
    $user = User::factory()->create();
    $personal = resolve(CreateOrganization::class)->handle($user, 'Personal', personal: true);
    $solo = resolve(CreateOrganization::class)->handle($user, 'Acme');

    whileClosingAnAccount(fn () => resolve(DeleteUser::class)->handle($user));

    $memberships = TenantQueryGuard::allowUnscoped(
        fn (): int => Membership::query()->withoutTenantScope()->count()
    );

    expect(User::query()->find($user->id))->toBeNull()
        ->and(Organization::query()->find($personal->id))->toBeNull()
        ->and(Organization::query()->find($solo->id))->toBeNull()
        ->and($memberships)->toBe(0);
});

it('lets the account be deleted once ownership has moved on', function (): void {
    $owner = User::factory()->create();
    $successor = User::factory()->create();

    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    resolve(AddOrganizationMember::class)->handle($organization, $successor);
    resolve(TransferOrganizationOwnership::class)->handle($organization, $successor);

    whileClosingAnAccount(fn () => resolve(DeleteUser::class)->handle($owner));

    expect(User::query()->find($owner->id))->toBeNull()
        ->and(Organization::query()->find($organization->id)?->owner_id)->toBe($successor->id);
});
