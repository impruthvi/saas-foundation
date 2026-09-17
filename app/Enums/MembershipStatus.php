<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a membership currently grants access.
 *
 * These are the only two states a membership can hold, because a membership only
 * exists once someone is in. The states *before* that — pending, revoked,
 * declined — belong to `InvitationStatus`, on a row that is not a membership
 * yet. M1 guessed they would land here; M2 put them where the fact lives.
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
