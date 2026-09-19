<?php

declare(strict_types=1);

namespace App\Actions;

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
 * Turns an offer into a membership, for the person it was addressed to.
 *
 * The invitation is resolved before its tenant is known, so every write runs
 * inside the invitation's organization. A row lock serializes concurrent
 * accepts; the unique-constraint catch remains a final safety net.
 */
final readonly class AcceptOrganizationInvitation
{
    public function __construct(
        private TenantContext $tenant,
        private AddOrganizationMember $members,
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
     * Every reason this invitation cannot be taken by this person.
     *
     * Ordered by what the recipient most needs to hear: the invitation's own
     * state first, then whether it is theirs, then whether the organization is
     * still open. Each reason is a distinct class, because "too late", "not for
     * you" and "not right now" are three different answers and collapsing them
     * into one would lose information the recipient needs.
     *
     * Public because the accept screen asks the same question without acting on
     * the answer: it renders the reason instead of throwing it. One ladder, so
     * the screen and the endpoint cannot drift apart about why.
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
