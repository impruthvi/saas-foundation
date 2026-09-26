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

    public function label(): string
    {
        return match ($this) {
            self::ViewMembers => __('See members'),
            self::InviteMembers => __('Invite people'),
            self::ManageMembers => __('Manage members'),
            self::ManageBilling => __('Manage billing'),
            self::ManageProjects => __('Create and manage projects'),
        };
    }
}
