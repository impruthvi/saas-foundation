<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Expiry is derived from expires_at so a stored status cannot disagree with the clock.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Revoked = 'revoked';

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
