<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A named bundle of permissions, granted inside one organization.
 *
 * `forRank()` is the only translation from a membership's writable rank to its
 * RBAC role. Ownership remains a separate fact on the organization.
 */
enum OrganizationRole: string
{
    case Admin = 'admin';
    case Member = 'member';

    public static function forRank(MembershipRole $rank): self
    {
        return match ($rank) {
            MembershipRole::Admin => self::Admin,
            MembershipRole::Member => self::Member,
        };
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => [
                Permission::ViewMembers,
                Permission::InviteMembers,
                Permission::ManageMembers,
                Permission::ManageBilling,
                Permission::ManageProjects,
            ],
            self::Member => [
                Permission::ViewMembers,
                Permission::ManageProjects,
            ],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Admin'),
            self::Member => __('Member'),
        };
    }
}
