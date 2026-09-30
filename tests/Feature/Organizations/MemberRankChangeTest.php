<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\ChangeOrganizationMemberRank;
use App\Actions\CreateOrganization;
use App\Enums\MembershipRank;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Exceptions\Memberships\LastAdministrator;
use App\Exceptions\Memberships\OwnerCannotBeDemoted;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;

function membershipOf(User $user, Organization $organization): Membership
{
    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Membership => Membership::query()->where('user_id', $user->id)->sole(),
    );
}

it('promotes a member, and the promotion is what lets them invite', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $member);

    expect(mayWithin($member, $organization->id, Permission::InviteMembers))->toBeFalse();

    $membership = resolve(ChangeOrganizationMemberRank::class)->handle($membership, MembershipRank::Admin);

    expect($membership->role)->toBe(MembershipRank::Admin)
        ->and(mayWithin($member, $organization->id, Permission::InviteMembers))->toBeTrue();
});

it('leaves a promoted member holding one role and not two', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $member);

    resolve(ChangeOrganizationMemberRank::class)->handle($membership, MembershipRank::Admin);

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
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $second, MembershipRank::Admin);

    $membership = resolve(ChangeOrganizationMemberRank::class)->handle($membership, MembershipRank::Member);

    expect($membership->role)->toBe(MembershipRank::Member)
        ->and(mayWithin($second, $organization->id, Permission::InviteMembers))->toBeFalse()
        ->and(mayWithin($second, $organization->id, Permission::ViewMembers))->toBeTrue();
});

it('refuses to demote the owner, whatever the administrator count says', function (): void {
    $owner = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    resolve(AddOrganizationMember::class)->handle($organization, User::factory()->create(), MembershipRank::Admin);

    $membership = membershipOf($owner, $organization);

    try {
        resolve(ChangeOrganizationMemberRank::class)->handle($membership, MembershipRank::Member);
        $this->fail('Demoting the owner should have been refused.');
    } catch (OwnerCannotBeDemoted $ownerCannotBeDemoted) {
        expect($ownerCannotBeDemoted->getMessage())->toContain('Acme')
            ->and(mayWithin($owner, $organization->id, Permission::ManageBilling))->toBeTrue();
    }
});

it('refuses to demote the last administrator who is not the owner', function (): void {
    $owner = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    $admin = User::factory()->create();
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $admin, MembershipRank::Admin);

    // The suspended owner holds the rank and grants nothing, so the other administrator
    // is the only one running the organization.
    membershipOf($owner, $organization)
        ->forceFill(['status' => MembershipStatus::Suspended])->save();

    try {
        resolve(ChangeOrganizationMemberRank::class)->handle($membership, MembershipRank::Member);
        $this->fail('Demoting the last active administrator should have been refused.');
    } catch (LastAdministrator $lastAdministrator) {
        expect($lastAdministrator->getMessage())->toContain('Acme')
            ->and(mayWithin($admin, $organization->id, Permission::InviteMembers))->toBeTrue();
    }
});

it('demotes a suspended administrator while an active one remains', function (): void {
    $owner = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    $suspended = User::factory()->create();
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $suspended, MembershipRank::Admin);

    $membership->forceFill(['status' => MembershipStatus::Suspended])->save();

    $demoted = resolve(ChangeOrganizationMemberRank::class)->handle($membership, MembershipRank::Member);

    expect($demoted->role)->toBe(MembershipRank::Member);
});

it('does nothing when the rank is already the one asked for', function (): void {
    $owner = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');

    $membership = membershipOf($owner, $organization);

    $unchanged = resolve(ChangeOrganizationMemberRank::class)->handle($membership, MembershipRank::Admin);

    expect($unchanged->role)->toBe(MembershipRank::Admin)
        ->and(mayWithin($owner, $organization->id, Permission::ManageBilling))->toBeTrue();
});

it('changes nothing in another organization', function (): void {
    $person = User::factory()->create();

    $acme = resolve(CreateOrganization::class)->handle(User::factory()->create(), 'Acme');
    $other = resolve(CreateOrganization::class)->handle(User::factory()->create(), 'Other');

    $membership = resolve(AddOrganizationMember::class)->handle($acme, $person);
    resolve(AddOrganizationMember::class)->handle($other, $person, MembershipRank::Admin);

    resolve(ChangeOrganizationMemberRank::class)->handle($membership, MembershipRank::Admin);

    expect(mayWithin($person, $acme->id, Permission::ManageBilling))->toBeTrue()
        ->and(mayWithin($person, $other->id, Permission::ManageBilling))->toBeTrue();

    $back = resolve(ChangeOrganizationMemberRank::class)->handle($membership, MembershipRank::Member);

    expect($back->role)->toBe(MembershipRank::Member)
        ->and(mayWithin($person, $acme->id, Permission::ManageBilling))->toBeFalse()
        ->and(mayWithin($person, $other->id, Permission::ManageBilling))->toBeTrue();
});
