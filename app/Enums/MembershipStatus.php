<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a membership currently grants access.
 *
 * M2's invitations add the states before acceptance; these are the two a
 * membership can hold once it exists.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }
}
