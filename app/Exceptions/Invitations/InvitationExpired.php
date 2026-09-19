<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

use App\Models\Invitation;

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
