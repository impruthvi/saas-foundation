<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

final class InvitationDeclined extends InvitationRefused
{
    public static function make(): self
    {
        return new self('This invitation was declined. Ask for a new one.');
    }
}
