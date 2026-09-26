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
 * Order matters: resolved tenant, active membership, owner fallback, then the
 * permission, so suspended members cannot act and an owner keeps a recovery path if
 * roles drift.
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
     * hasPermissionTo() because the package's gate hook is disabled. Relations are
     * unset because they are cached per instance and the organization can change within
     * a request.
     */
    private function may(User $user, Permission $permission): bool
    {
        return $user->unsetRelation('roles')
            ->unsetRelation('permissions')
            ->hasPermissionTo($permission->value);
    }
}
