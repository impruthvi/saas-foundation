<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

final class AlreadyMember extends InvitationRefused
{
    public static function of(string $email): self
    {
        return new self("{$email} is already a member of this organization.");
    }
}
