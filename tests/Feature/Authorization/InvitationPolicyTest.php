<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Enums\MembershipRank;
use App\Enums\MembershipStatus;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

function mayManageInvitations(User $user, Organization $organization): bool
{
    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): bool => $user->can('create', Invitation::class),
    );
}

function maySeeInvitations(User $user, Organization $organization): bool
{
    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): bool => $user->can('viewAny', Invitation::class),
    );
}

it('lets the owner manage invitations', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();

    expect(mayManageInvitations($owner, $organization))->toBeTrue();
});

it('lets an administrator manage invitations', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $admin = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $admin, MembershipRank::Admin);

    expect(mayManageInvitations($admin, $organization))->toBeTrue();
});

it('lets a plain member see invitations but not manage them', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $member);

    expect(maySeeInvitations($member, $organization))->toBeTrue()
        ->and(mayManageInvitations($member, $organization))->toBeFalse();
});

it('refuses a suspended administrator, whose rank still says Admin', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $admin = User::factory()->create();
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $admin, MembershipRank::Admin);

    $membership->forceFill(['status' => MembershipStatus::Suspended])->save();

    expect(mayManageInvitations($admin, $organization))->toBeFalse()
        ->and(maySeeInvitations($admin, $organization))->toBeFalse();
});

it('still lets the owner invite when their role assignment has gone missing', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();

    DB::table('model_has_roles')->where('organization_id', $organization->id)->delete();

    expect(mayManageInvitations($owner, $organization))->toBeTrue();
});

it('fails closed for an administrator who is not the owner and has no assignment', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $admin = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $admin, MembershipRank::Admin);

    DB::table('model_has_roles')
        ->where('organization_id', $organization->id)
        ->where('model_id', $admin->id)
        ->delete();

    expect(mayManageInvitations($admin, $organization))->toBeFalse();
});

it('refuses somebody who is not a member at all', function (): void {
    [$organization] = organizationOwnedBySomeone();

    expect(mayManageInvitations(User::factory()->create(), $organization))->toBeFalse()
        ->and(maySeeInvitations(User::factory()->create(), $organization))->toBeFalse();
});

it('refuses everyone when no organization is resolved', function (): void {
    [, $owner] = organizationOwnedBySomeone();

    resolve(TenantContext::class)->forget();

    expect($owner->can('create', Invitation::class))->toBeFalse();
});
