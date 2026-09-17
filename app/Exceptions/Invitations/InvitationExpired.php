<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

use App\Models\Invitation;

/**
 * Raised when an invitation is taken after `expires_at` has passed.
 *
 * Expiry is derived from the timestamp and never stored as a status: one fact,
 * one home. Nothing has to sweep the table for this to become true.
 */
final class InvitationExpired extends InvitationRefused
{
    public static function on(Invitation $invitation): self
    {
        return new self(
            'This invitation expired on '.$invitation->expires_at->toFormattedDateString()
            .'. Ask for a new one.'
        );
    }
}
