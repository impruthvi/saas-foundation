<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvitationStatus;
use App\Exceptions\Invitations\InvitationAddressedToAnother;
use App\Exceptions\Invitations\InvitationAlreadyAccepted;
use App\Exceptions\Invitations\InvitationDeclined;
use App\Exceptions\Invitations\InvitationExpired;
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
 * Turns an offer into a membership, for the person it was addressed to.
 *
 * The only action in the application that crosses a tenant boundary on purpose,
 * and the reason D27 exists. The invitation was resolved outside any tenant, by
 * `InvitationRepository`; everything this writes has to happen inside the
 * invitation's own organization.
 *
 *   no tenant resolved (the invitee's own org, or none)
 *            │
 *            ▼
 *   refusal ladder — six reasons, six exception classes
 *            │ passes
 *            ▼
 *   runForId($invitation->organization_id)     ◄── the integer, never the relation
 *            │                                     (ShouldBeStrict is on app-wide,
 *            ▼                                      so reading it would throw)
 *   DB::transaction
 *     ├── lockForUpdate + re-read status   ◄── the atomic part
 *     ├── AddOrganizationMember
 *     └── stamp accepted_at / accepted_by
 *
 * The lock is what makes a double-click safe. Two requests both pass the ladder,
 * both enter the transaction, and one waits: it re-reads the row, finds it
 * `Accepted`, and raises `InvitationAlreadyAccepted` rather than inserting a
 * second membership against the unique index on `(organization_id, user_id)`.
 * The caught constraint violation below is the belt to that lock's braces —
 * unreachable if the lock does its job, and a 500 turned into a clear answer if
 * it ever does not.
 */
final readonly class AcceptOrganizationInvitation
{
    public function __construct(
        private TenantContext $tenant,
        private AddOrganizationMember $members,
    ) {}

    public function handle(Invitation $invitation, User $user): Membership
    {
        $this->refuse($invitation, $user);

        return $this->tenant->runForId(
            $invitation->organization_id,
            fn (): Membership => $this->accept($invitation, $user),
        );
    }

    /**
     * Every reason this invitation cannot be taken by this person.
     *
     * Ordered by what the recipient most needs to hear: the invitation's own
     * state first, then whether it is theirs, then whether the organization is
     * still open. Each reason is a distinct class, because "too late", "not for
     * you" and "not right now" are three different answers and collapsing them
     * into one is the failure M2 exists to avoid.
     */
    private function refuse(Invitation $invitation, User $user): void
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
     * The organization, loaded by key rather than through the relation.
     *
     * `organizations` is not tenant-owned, so this needs no scope standing down;
     * it is a query rather than `$invitation->organization` because lazy loading
     * is prevented application-wide and this runs with no tenant resolved.
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

            return $membership;
        });
    }
}
