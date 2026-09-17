<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

/**
 * Raised when an invitation the recipient already declined is taken.
 *
 * Declining is final for that invitation, not for the address: an administrator
 * can invite again, which rotates the row and issues a fresh token.
 */
final class InvitationDeclined extends InvitationRefused
{
    public static function make(): self
    {
        return new self('This invitation was declined. Ask for a new one.');
    }
}
