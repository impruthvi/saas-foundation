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
 * The ladder every organization policy climbs.
 *
 * Three questions in order, each answering something the next cannot:
 *
 *   no organization resolved ─────────────────────► false
 *              │
 *   not an active member ─────────────────────────► false   (status; a permission cannot say it)
 *              │
 *   owner_id === user ────────────────────────────► true    (survives a drifted assignment, D31)
 *              │
 *   hasPermissionTo(permission) ──────────────────► the configurable part
 *
 * It lives here rather than in the first policy that needed it because the
 * second policy, and the members screen's per-row chrome, have to give the same
 * answer. A copy that drops the status rung lets a suspended administrator act;
 * a copy that drops the owner rung hides the repair path from the one person
 * who can use it.
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
     * `hasPermissionTo()` rather than `can()`: D29 turns off the package's gate
     * hook, so `can()` never sees a permission name. The relations are unset
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
