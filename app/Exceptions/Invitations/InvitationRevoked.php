<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

final class InvitationRevoked extends InvitationRefused
{
    public static function make(): self
    {
        return new self('This invitation was withdrawn by the organization.');
    }
}
