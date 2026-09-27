<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\InvitationStatus;
use App\Exceptions\Invitations\InvitationAddressedToAnother;
use App\Exceptions\Invitations\InvitationAlreadyAccepted;
use App\Exceptions\Invitations\InvitationDeclined;
use App\Exceptions\Invitations\InvitationExpired;
use App\Exceptions\Invitations\InvitationRefused;
use App\Exceptions\Invitations\InvitationRevoked;
use App\Exceptions\Invitations\OrganizationNotAcceptingMembers;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Resolved before its tenant is known, so every write runs inside the invitation's
 * organization. A row lock serializes concurrent accepts; the unique-constraint catch
 * is the last safety net.
 */
final readonly class AcceptOrganizationInvitation
{
    public function __construct(
        private TenantContext $tenant,
        private AddOrganizationMember $members,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Invitation $invitation, User $user): Membership
    {
        $this->assertAcceptableBy($invitation, $user);

        return $this->tenant->runForId(
            $invitation->organization_id,
            fn (): Membership => $this->accept($invitation, $user),
        );
    }

    /**
     * Public so the accept screen can render the refusal instead of throwing it, from
     * the same ladder the endpoint uses.
     *
     * @throws InvitationRefused
     */
    public function assertAcceptableBy(Invitation $invitation, User $user): void
    {
        match ($invitation->status) {
            InvitationStatus::Accepted => throw InvitationAlreadyAccepted::make(),
            InvitationStatus::Revoked => throw InvitationRevoked::make(),
            InvitationStatus::Declined => throw InvitationDeclined::make(),
            InvitationStatus::Pending => null,
        };

        if ($invitation->hasExpired()) {
            throw InvitationExpired::on($invitation);
        }

        if (! $invitation->wasAddressedTo($user->email)) {
            throw InvitationAddressedToAnother::addressedTo($invitation->email);
        }

        $organization = $this->organizationOf($invitation);

        if (! $organization->status->isUsable()) {
            throw OrganizationNotAcceptingMembers::for($organization);
        }
    }

    /**
     * Queried by key: lazy loading is prevented and no tenant is resolved here.
     */
    private function organizationOf(Invitation $invitation): Organization
    {
        return Organization::query()->findOrFail($invitation->organization_id);
    }

    private function accept(Invitation $invitation, User $user): Membership
    {
        return DB::transaction(function () use ($invitation, $user): Membership {
            $locked = Invitation::query()
                ->lockForUpdate()
                ->findOrFail($invitation->id);

            if ($locked->status === InvitationStatus::Accepted) {
                throw InvitationAlreadyAccepted::make();
            }

            try {
                $membership = $this->members->handle(
                    $this->organizationOf($locked),
                    $user,
                    $locked->role,
                );
            } catch (UniqueConstraintViolationException) {
                throw InvitationAlreadyAccepted::make();
            }

            $locked->forceFill([
                'status' => InvitationStatus::Accepted,
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->id,
            ])->save();

            $this->audit->handle($locked->organization_id, AuditAction::InvitationAccepted, $locked, [
                'email' => $locked->email,
                'user_id' => $user->id,
                'rank' => $locked->role->value,
            ]);

            return $membership;
        });
    }
}
