<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * An invitation's persisted state. Expiry is derived from `expires_at` so the
 * clock and a stored status cannot disagree.
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
