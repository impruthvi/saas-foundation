<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\ChangeOrganizationMemberRole;
use App\Actions\CreateOrganization;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Exceptions\Memberships\LastAdministrator;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\TenantContext;

/*
|--------------------------------------------------------------------------
| Promotion and demotion move rank and role together
|--------------------------------------------------------------------------
|
| Rank is the writable fact and the role assignment is its projection (D31),
| so the interesting assertions are never "the column changed" on its own. They
| are the column and what the person can actually do, checked together, because
| the failure this milestone can produce silently is the two disagreeing.
|
*/

it('promotes a member, and the promotion is what lets them invite', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $member);

    expect(mayWithin($member, $organization->id, Permission::InviteMembers))->toBeFalse();

    $membership = resolve(ChangeOrganizationMemberRole::class)->handle($membership, MembershipRole::Admin);

    expect($membership->role)->toBe(MembershipRole::Admin)
        ->and(mayWithin($member, $organization->id, Permission::InviteMembers))->toBeTrue();
});

it('leaves a promoted member holding one role and not two', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $member);

    resolve(ChangeOrganizationMemberRole::class)->handle($membership, MembershipRole::Admin);

    $roles = resolve(TenantContext::class)->runForId(
        $organization->id,
        fn (): array => $member->unsetRelation('roles')->getRoleNames()->all(),
    );

    expect($roles)->toBe(['admin']);
});

it('demotes an administrator while another one remains', function (): void {
    $owner = User::factory()->create();
    $second = User::factory()->create();

    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $second, MembershipRole::Admin);

    $membership = resolve(ChangeOrganizationMemberRole::class)->handle($membership, MembershipRole::Member);

    expect($membership->role)->toBe(MembershipRole::Member)
        ->and(mayWithin($second, $organization->id, Permission::InviteMembers))->toBeFalse()
        ->and(mayWithin($second, $organization->id, Permission::ViewMembers))->toBeTrue();
});

it('refuses to demote the last administrator', function (): void {
    $owner = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    resolve(AddOrganizationMember::class)->handle($organization, User::factory()->create());

    $membership = resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Membership => Membership::query()->where('user_id', $owner->id)->sole(),
    );

    try {
        resolve(ChangeOrganizationMemberRole::class)->handle($membership, MembershipRole::Member);
        $this->fail('Demoting the last administrator should have been refused.');
    } catch (LastAdministrator $lastAdministrator) {
        expect($lastAdministrator->getMessage())->toContain('Acme')
            ->and(mayWithin($owner, $organization->id, Permission::InviteMembers))->toBeTrue();
    }
});

it('does nothing when the rank is already the one asked for', function (): void {
    $owner = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');

    $membership = resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Membership => Membership::query()->where('user_id', $owner->id)->sole(),
    );

    $unchanged = resolve(ChangeOrganizationMemberRole::class)->handle($membership, MembershipRole::Admin);

    expect($unchanged->role)->toBe(MembershipRole::Admin)
        ->and(mayWithin($owner, $organization->id, Permission::ManageBilling))->toBeTrue();
});

it('changes nothing in another organization', function (): void {
    $person = User::factory()->create();

    $acme = resolve(CreateOrganization::class)->handle(User::factory()->create(), 'Acme');
    $other = resolve(CreateOrganization::class)->handle(User::factory()->create(), 'Other');

    $membership = resolve(AddOrganizationMember::class)->handle($acme, $person);
    resolve(AddOrganizationMember::class)->handle($other, $person, MembershipRole::Admin);

    resolve(ChangeOrganizationMemberRole::class)->handle($membership, MembershipRole::Admin);

    expect(mayWithin($person, $acme->id, Permission::ManageBilling))->toBeTrue()
        ->and(mayWithin($person, $other->id, Permission::ManageBilling))->toBeTrue();

    $back = resolve(ChangeOrganizationMemberRole::class)->handle($membership, MembershipRole::Member);

    expect($back->role)->toBe(MembershipRole::Member)
        ->and(mayWithin($person, $acme->id, Permission::ManageBilling))->toBeFalse()
        ->and(mayWithin($person, $other->id, Permission::ManageBilling))->toBeTrue();
});
