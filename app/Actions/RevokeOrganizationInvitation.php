<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvitationStatus;
use App\Exceptions\Invitations\InvitationAlreadyAccepted;
use App\Models\Invitation;
use App\Models\User;

/**
 * Withdraws an offer the organization no longer wants to stand behind.
 *
 * Revoke and decline are the same state change reached from opposite sides —
 * one by the organization, one by the recipient — so they share the transition
 * and differ only in who is stamped on it. They stay two actions because they
 * are two verbs with two authorization rules: an administrator revokes, and only
 * the addressee declines.
 *
 * An accepted invitation cannot be revoked. Removing somebody who is already a
 * member is a membership operation, and quietly reusing this for it would leave
 * the member in place while the paperwork said otherwise.
 */
final readonly class RevokeOrganizationInvitation
{
    public function handle(Invitation $invitation, ?User $revokedBy = null): Invitation
    {
        if ($invitation->status === InvitationStatus::Accepted) {
            throw InvitationAlreadyAccepted::make();
        }

        return $invitation->close(InvitationStatus::Revoked, [
            'revoked_at' => now(),
            'revoked_by_user_id' => $revokedBy?->id,
        ]);
    }
}
