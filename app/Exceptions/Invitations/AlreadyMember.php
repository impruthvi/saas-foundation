<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

/**
 * Raised at invite time when the address already belongs to an active member.
 *
 * Distinct from AlreadyInvited because the remedies are opposites: one says
 * resend, the other says there is nothing to do.
 */
final class AlreadyMember extends InvitationRefused
{
    public static function of(string $email): self
    {
        return new self("{$email} is already a member of this organization.");
    }
}
