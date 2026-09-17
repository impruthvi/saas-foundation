<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

/**
 * Raised when an invitation that an administrator withdrew is taken anyway.
 *
 * Distinct from expiry on purpose: one of them is the passage of time and the
 * other is a decision somebody made, and the recipient is owed the difference.
 */
final class InvitationRevoked extends InvitationRefused
{
    public static function make(): self
    {
        return new self('This invitation was withdrawn by the organization.');
    }
}
