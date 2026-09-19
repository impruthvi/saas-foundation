<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\Permission;
use App\Models\User;

trait ChecksOrganizationPermissions
{
    /**
     * Whether the user holds the permission in the resolved organization.
     *
     * `hasPermissionTo()` rather than `can()`: D29 turns off the package's
     * gate hook, so `can()` never sees a permission name. The relations are
     * unset first because they are cached per instance and the resolved
     * organization can change within one request.
     */
    private function may(User $user, Permission $permission): bool
    {
        return $user->unsetRelation('roles')
            ->unsetRelation('permissions')
            ->hasPermissionTo($permission->value);
    }
}
