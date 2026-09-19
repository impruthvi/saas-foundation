<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

final class InvitationAddressedToAnother extends InvitationRefused
{
    public static function addressedTo(string $email): self
    {
        return new self(
            "This invitation was sent to {$email}. Sign out and sign in as that account to accept it."
        );
    }
}
