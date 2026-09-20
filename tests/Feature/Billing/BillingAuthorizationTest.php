<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** @return array{view: bool, manage: bool} */
function billingPermissions(User $user, Organization $organization): array
{
    return resolve(TenantContext::class)->runFor($organization, fn (): array => [
        'view' => $user->can('viewAny', Subscription::class),
        'manage' => $user->can('manage', Subscription::class),
    ]);
}

it('lets the owner view and manage billing', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();

    expect(billingPermissions($owner, $organization))->toBe([
        'view' => true,
        'manage' => true,
    ]);
});

it('lets an administrator view and manage billing', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $admin = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $admin, MembershipRole::Admin);

    expect(billingPermissions($admin, $organization))->toBe([
        'view' => true,
        'manage' => true,
    ]);
});

it('lets a plain member view billing without managing it', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $member);

    expect(billingPermissions($member, $organization))->toBe([
        'view' => true,
        'manage' => false,
    ]);
});

it('refuses a suspended administrator', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $admin = User::factory()->create();
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $admin, MembershipRole::Admin);
    $membership->forceFill(['status' => MembershipStatus::Suspended])->save();

    expect(billingPermissions($admin, $organization))->toBe([
        'view' => false,
        'manage' => false,
    ]);
});

it('keeps the owner floor when their role assignment is missing', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    DB::table('model_has_roles')->where('organization_id', $organization->id)->delete();

    expect(billingPermissions($owner, $organization))->toBe([
        'view' => true,
        'manage' => true,
    ]);
});

it('refuses everyone when no organization is resolved', function (): void {
    [, $owner] = organizationOwnedBySomeone();
    resolve(TenantContext::class)->forget();

    expect($owner->can('viewAny', Subscription::class))->toBeFalse()
        ->and($owner->can('manage', Subscription::class))->toBeFalse();
});

it('requires authentication', function (): void {
    $this->get(route('organizations.billing.index'))->assertRedirect(route('login'));
});

it('refuses a request without an active organization membership', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->get(route('organizations.billing.index'))
        ->assertForbidden();
});
