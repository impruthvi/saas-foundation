<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\RemoveOrganizationMember;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Exceptions\Memberships\LastAdministrator;
use App\Exceptions\Memberships\OwnerCannotBeRemoved;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The way out
|--------------------------------------------------------------------------
|
| M2 built the way into an organization and left none out. The rules are the
| ones M3 introduces: who may remove whom, and what happens to the last
| administrator.
|
| The assertion that matters most is never "the row is gone". It is that the
| grants went with it — `model_has_roles` is keyed to organizations and users,
| not to memberships, so a removal that only deletes the membership leaves
| somebody holding every permission they had, with nothing left to notice.
|
*/

function stillAMember(Membership $membership, Organization $organization): bool
{
    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): bool => Membership::query()->whereKey($membership->id)->exists(),
    );
}

function removalOf(User $user, Organization $organization): Membership
{
    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Membership => Membership::query()->where('user_id', $user->id)->sole(),
    );
}

it('removes a member and takes their grants with them', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $member, MembershipRole::Admin);

    expect(mayWithin($member, $organization->id, Permission::InviteMembers))->toBeTrue();

    resolve(RemoveOrganizationMember::class)->handle($membership);

    expect(stillAMember($membership, $organization))->toBeFalse()
        ->and(mayWithin($member, $organization->id, Permission::InviteMembers))->toBeFalse()
        ->and(mayWithin($member, $organization->id, Permission::ViewMembers))->toBeFalse();
});

it('revokes a direct permission along with the role', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $member);

    resolve(TenantContext::class)->runFor(
        $organization,
        fn () => $member->givePermissionTo(Permission::ManageBilling->value),
    );

    resolve(RemoveOrganizationMember::class)->handle($membership);

    $rows = DB::table('model_has_permissions')
        ->where('organization_id', $organization->id)
        ->where('model_id', $member->id)
        ->count();

    expect($rows)->toBe(0);
});

it('leaves the same person their grants in another organization', function (): void {
    $person = User::factory()->create();
    [$acme] = organizationOwnedBySomeone('Acme');
    [$other] = organizationOwnedBySomeone('Other');

    $membership = resolve(AddOrganizationMember::class)->handle($acme, $person, MembershipRole::Admin);
    resolve(AddOrganizationMember::class)->handle($other, $person, MembershipRole::Admin);

    resolve(RemoveOrganizationMember::class)->handle($membership);

    expect(mayWithin($person, $acme->id, Permission::InviteMembers))->toBeFalse()
        ->and(mayWithin($person, $other->id, Permission::InviteMembers))->toBeTrue();
});

it('refuses to remove the owner', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    resolve(AddOrganizationMember::class)->handle($organization, User::factory()->create(), MembershipRole::Admin);

    try {
        resolve(RemoveOrganizationMember::class)->handle(removalOf($owner, $organization));
        $this->fail('Removing the owner should have been refused.');
    } catch (OwnerCannotBeRemoved $ownerCannotBeRemoved) {
        expect($ownerCannotBeRemoved->getMessage())->toContain('Acme')
            ->and(mayWithin($owner, $organization->id, Permission::ManageBilling))->toBeTrue();
    }
});

it('refuses to remove the last active administrator', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $admin = User::factory()->create();
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $admin, MembershipRole::Admin);

    removalOf($owner, $organization)->forceFill(['status' => MembershipStatus::Suspended])->save();

    try {
        resolve(RemoveOrganizationMember::class)->handle($membership);
        $this->fail('Removing the last active administrator should have been refused.');
    } catch (LastAdministrator $lastAdministrator) {
        expect($lastAdministrator->getMessage())->toContain('Acme')
            ->and(mayWithin($admin, $organization->id, Permission::InviteMembers))->toBeTrue();
    }
});

it('removes a suspended administrator, who was granting nothing anyway', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $suspended = User::factory()->create();
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $suspended, MembershipRole::Admin);
    $membership->forceFill(['status' => MembershipStatus::Suspended])->save();

    resolve(RemoveOrganizationMember::class)->handle($membership);

    expect(stillAMember($membership, $organization))->toBeFalse();
});

it('lets an administrator remove themselves while another remains', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $leaving = User::factory()->create();
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $leaving, MembershipRole::Admin);

    resolve(RemoveOrganizationMember::class)->handle($membership);

    expect(mayWithin($leaving, $organization->id, Permission::ViewMembers))->toBeFalse();
});

it('agrees with the policy about every membership it refuses', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $admin, MembershipRole::Admin);
    resolve(AddOrganizationMember::class)->handle($organization, $member);

    $verdicts = resolve(TenantContext::class)->runFor($organization, function () use ($owner, $admin, $member): array {
        $policy = [];

        foreach ([$owner, $admin, $member] as $subject) {
            $membership = Membership::query()->where('user_id', $subject->id)->sole();
            $policy[$subject->id] = $owner->can('delete', $membership);
        }

        return $policy;
    });

    // The owner is refused; the other two are not, because one administrator
    // remains either way.
    expect($verdicts)->toBe([
        $owner->id => false,
        $admin->id => true,
        $member->id => true,
    ]);
});

it('refuses a plain member who tries to remove somebody', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    $other = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $member);
    $theirs = resolve(AddOrganizationMember::class)->handle($organization, $other);

    $allowed = resolve(TenantContext::class)->runFor(
        $organization,
        fn (): bool => $member->can('delete', $theirs),
    );

    expect($allowed)->toBeFalse();
});

it('removes a member over HTTP and reports it', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $member);

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->delete(route('organizations.members.destroy', $membership))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(stillAMember($membership, $organization))->toBeFalse();
});

it('refuses over HTTP when a plain member tries to remove somebody', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $member);
    $theirs = resolve(AddOrganizationMember::class)->handle($organization, User::factory()->create());

    $this->actingAs($member)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->delete(route('organizations.members.destroy', $theirs))
        ->assertForbidden();

    expect(stillAMember($theirs, $organization))->toBeTrue();
});

it('refuses over HTTP to remove the owner', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $admin = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $admin, MembershipRole::Admin);

    $this->actingAs($admin)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->delete(route('organizations.members.destroy', removalOf($owner, $organization)))
        ->assertForbidden();
});

it('cannot reach a membership belonging to another organization', function (): void {
    [$acme, $owner] = organizationOwnedBySomeone('Acme');
    [$other] = organizationOwnedBySomeone('Other');
    $theirs = resolve(AddOrganizationMember::class)->handle($other, User::factory()->create());

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $acme->id])
        ->delete(route('organizations.members.destroy', $theirs))
        ->assertNotFound();

    expect(stillAMember($theirs, $other))->toBeTrue();
});

it('changes a rank over HTTP', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $member);

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->patch(route('organizations.members.update', $membership), ['role' => MembershipRole::Admin->value])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(mayWithin($member, $organization->id, Permission::InviteMembers))->toBeTrue();
});

it('refuses over HTTP to change the owner rank', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $admin = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $admin, MembershipRole::Admin);

    $this->actingAs($admin)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->patch(route('organizations.members.update', removalOf($owner, $organization)), [
            'role' => MembershipRole::Member->value,
        ])
        ->assertForbidden();
});
