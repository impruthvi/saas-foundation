<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\InvitationStatus;
use App\Exceptions\Invitations\InvitationAlreadyAccepted;
use App\Exceptions\Invitations\InvitationDeclined;
use App\Exceptions\Invitations\InvitationRevoked;
use App\Models\Invitation;
use Illuminate\Support\Facades\DB;

/**
 * Rotates the token so only the newest email works. Only a pending invitation, expired
 * or not, can be resent.
 */
final readonly class ResendOrganizationInvitation
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * @return array{invitation: Invitation, token: string}
     */
    public function handle(Invitation $invitation): array
    {
        return DB::transaction(function () use ($invitation): array {
            match ($invitation->status) {
                InvitationStatus::Accepted => throw InvitationAlreadyAccepted::make(),
                InvitationStatus::Revoked => throw InvitationRevoked::make(),
                InvitationStatus::Declined => throw InvitationDeclined::make(),
                InvitationStatus::Pending => null,
            };

            $token = $invitation->issueToken();

            $invitation->forceFill([
                'expires_at' => now()->addDays(config()->integer('organizations.invitations.expires_after_days')),
            ])->save();

            $this->audit->handle($invitation->organization_id, AuditAction::InvitationResent, $invitation, [
                'email' => $invitation->email,
            ]);

            return ['invitation' => $invitation, 'token' => $token];
        });
    }
}
