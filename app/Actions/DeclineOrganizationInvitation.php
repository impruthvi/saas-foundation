<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvitationStatus;
use App\Exceptions\Invitations\InvitationAlreadyAccepted;
use App\Models\Invitation;

/**
 * Turns an offer down, on the recipient's side.
 *
 * The mirror of RevokeOrganizationInvitation: same transition, different actor,
 * different authorization. Declining closes this invitation and not the address
 * — an administrator can invite again, which rotates the row and mints a new
 * token.
 */
final readonly class DeclineOrganizationInvitation
{
    public function handle(Invitation $invitation): Invitation
    {
        if ($invitation->status === InvitationStatus::Accepted) {
            throw InvitationAlreadyAccepted::make();
        }

        return $invitation->close(InvitationStatus::Declined);
    }
}
