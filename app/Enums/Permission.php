<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * One thing a member may do inside the organization the request is acting for.
 *
 * The catalog is small on purpose. Every case here has a caller in M3 or M4;
 * D11's scope filter applies to permissions exactly as it applies to features,
 * and a permission nobody asks for is a row that has to be kept true forever.
 *
 * The values are the strings `spatie/laravel-permission` stores, and the
 * migration that seeded them writes them literally rather than reading this
 * enum — a migration describes the database it built, not the code that came
 * later. `tests/Unit/PermissionCatalogTest.php` is what holds the two together.
 */
enum Permission: string
{
    case ViewMembers = 'organization.view_members';
    case InviteMembers = 'organization.invite';
    case ManageMembers = 'organization.manage_members';
    case ManageBilling = 'organization.manage_billing';

    /**
     * Every permission, as the strings the package stores.
     *
     * @return list<string>
     */
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
