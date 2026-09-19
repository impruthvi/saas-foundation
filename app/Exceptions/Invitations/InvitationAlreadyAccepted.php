<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

final class InvitationAlreadyAccepted extends InvitationRefused
{
    public static function make(): self
    {
        return new self('This invitation has already been accepted.');
    }
}
