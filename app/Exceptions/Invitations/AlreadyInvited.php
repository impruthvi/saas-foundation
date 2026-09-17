<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

/**
 * Raised at invite time when a live invitation for the address already exists.
 *
 * Naming the remedy matters here: the administrator's intent — "make sure they
 * get it" — is spelled resend, which rotates the token on the existing row
 * rather than leaving two live invitations for one address.
 */
final class AlreadyInvited extends InvitationRefused
{
    public static function to(string $email): self
    {
        return new self("{$email} already has a pending invitation. Resend it instead.");
    }
}
