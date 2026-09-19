<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A member's rank inside an organization.
 *
 * Ownership is stored only in `organizations.owner_id`; permissions belong to
 * the RBAC role derived from this rank.
 */
enum MembershipRole: string
{
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Admin'),
            self::Member => __('Member'),
        };
    }
}
