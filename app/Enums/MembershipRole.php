<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ownership lives only in organizations.owner_id.
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
