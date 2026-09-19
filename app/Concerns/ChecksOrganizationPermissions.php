<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\Permission;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use App\Tenancy\TenantContext;

/**
 * Centralizes the policy ladder: resolved tenant, active membership, owner
 * fallback, then the tenant-scoped permission. The order prevents suspended
 * members acting while preserving an owner's recovery path if roles drift.
 */
trait ChecksOrganizationPermissions
{
    abstract private function tenant(): TenantContext;

    abstract private function memberships(): MembershipRepository;

    private function allows(User $user, Permission $permission): bool
    {
        $organization = $this->tenant()->current();

        if (! $organization instanceof Organization) {
            return false;
        }

        if (! $this->memberships()->activeMembership($user, $organization) instanceof Membership) {
            return false;
        }

        return $organization->owner_id === $user->id
            || $this->may($user, $permission);
    }

    /**
     * Whether the user holds the permission in the resolved organization.
     *
     * `hasPermissionTo()` is required because the package's gate hook is
     * disabled. The relations are unset
     * first because they are cached per instance and the resolved organization
     * can change within one request.
     */
    private function may(User $user, Permission $permission): bool
    {
        return $user->unsetRelation('roles')
            ->unsetRelation('permissions')
            ->hasPermissionTo($permission->value);
    }
}
