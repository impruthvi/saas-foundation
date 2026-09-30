<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * forRank() is the only translation from rank to role.
 */
enum OrganizationRole: string
{
    case Admin = 'admin';
    case Member = 'member';

    public static function forRank(MembershipRank $rank): self
    {
        return match ($rank) {
            MembershipRank::Admin => self::Admin,
            MembershipRank::Member => self::Member,
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
}
