<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

final class AlreadyInvited extends InvitationRefused
{
    public static function to(string $email): self
    {
        return new self("{$email} already has a pending invitation. Resend it instead.");
    }
}
