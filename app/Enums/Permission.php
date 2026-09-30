<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The migration writes its catalog literally; a test keeps it in sync with this enum.
 */
enum Permission: string
{
    case ViewMembers = 'organization.view_members';
    case InviteMembers = 'organization.invite';
    case ManageMembers = 'organization.manage_members';
    case ManageBilling = 'organization.manage_billing';
    case ManageProjects = 'organization.manage_projects';

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
