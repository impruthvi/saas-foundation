<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

/**
 * Raised when an invitation that has already been taken is taken again.
 *
 * Two callers reach this. One is a person clicking an old link. The other is the
 * loser of a concurrent accept — two tabs, or a double submit — which finds the
 * row already `Accepted` under the row lock. The membership exists either way,
 * so a controller renders this as success rather than as a failure; it is an
 * exception because the action must not pretend to have created something.
 */
final class InvitationAlreadyAccepted extends InvitationRefused
{
    public static function make(): self
    {
        return new self('This invitation has already been accepted.');
    }
}
