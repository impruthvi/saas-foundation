<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A member capability within the resolved organization.
 *
 * The migration writes its historical catalog literally; the catalog test
 * keeps those rows synchronized with this enum.
 */
enum Permission: string
{
    case ViewMembers = 'organization.view_members';
    case InviteMembers = 'organization.invite';
    case ManageMembers = 'organization.manage_members';
    case ManageBilling = 'organization.manage_billing';

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
        };
    }
}
