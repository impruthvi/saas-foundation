<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Pending, revoked and declined describe invitations, not memberships.
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
