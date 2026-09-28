<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * An accepted invitation cannot be revoked; removing a member is a membership
 * operation.
 */
final readonly class RevokeOrganizationInvitation
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(Invitation $invitation, ?User $revokedBy = null): Invitation
    {
        return DB::transaction(function () use ($invitation, $revokedBy): Invitation {
            $invitation->freshLocked()->assertOpen();

            $invitation->close(InvitationStatus::Revoked, [
                'revoked_at' => now(),
                'revoked_by_user_id' => $revokedBy?->id,
            ]);

            $this->audit->handle($invitation->organization_id, AuditAction::InvitationRevoked, $invitation, [
                'email' => $invitation->email,
            ]);

            return $invitation;
        });
    }
}
