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
 * Sends the same offer again, on a new token and a new clock.
 *
 * Resending rotates the token deliberately. The newest email is the one that
 * counts, and leaving the old link alive would mean two live credentials for one
 * offer with no way to tell which was which. The previous link then resolves to
 * nothing, which the accepting controller renders as "no longer valid" — never
 * as "expired", because it was not.
 *
 * An expired-but-pending invitation may be resent; that is the ordinary case. A
 * revoked, declined or accepted one may not — those are decisions, and undoing
 * one is a fresh invitation rather than a resend.
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
