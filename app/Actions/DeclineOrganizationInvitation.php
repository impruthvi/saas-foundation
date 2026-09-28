<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\InvitationStatus;
use App\Exceptions\Invitations\InvitationAlreadyAccepted;
use App\Models\Invitation;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Declining closes this invitation, not the address: an administrator can invite again.
 */
final readonly class DeclineOrganizationInvitation
{
    public function __construct(
        private TenantContext $tenant,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Invitation $invitation): Invitation
    {
        if ($invitation->status === InvitationStatus::Accepted) {
            throw InvitationAlreadyAccepted::make();
        }

        return $this->tenant->runForId(
            $invitation->organization_id,
            fn (): Invitation => DB::transaction(function () use ($invitation): Invitation {
                $invitation->close(InvitationStatus::Declined);

                $this->audit->handle($invitation->organization_id, AuditAction::InvitationDeclined, $invitation, [
                    'email' => $invitation->email,
                ]);

                return $invitation;
            }),
        );
    }
}
