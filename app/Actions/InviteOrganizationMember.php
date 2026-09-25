<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\InvitationStatus;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Exceptions\Invitations\AlreadyInvited;
use App\Exceptions\Invitations\AlreadyMember;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Offers membership of an organization to an email address.
 *
 *   already an active member? ──yes──► AlreadyMember    (nothing to do)
 *            │ no
 *   live invitation already?  ──yes──► AlreadyInvited   (resend is the verb)
 *            │ no
 *            ▼
 *   create the row, or reuse the spent one for this address
 *            │
 *            ▼
 *   mint a token, return the plaintext exactly once
 *
 * Reuse rather than insert, because `(organization_id, email)` is unique: an
 * address that was revoked, declined or left to expire can be invited again, and
 * that rotates the existing row back to Pending with a fresh token. The old
 * token stops working the moment this returns, which is the point.
 *
 * The plaintext token is returned rather than stored anywhere. Only the caller
 * that is about to send the email ever sees it.
 */
final readonly class InviteOrganizationMember
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * @return array{invitation: Invitation, token: string}
     */
    public function handle(
        Organization $organization,
        string $email,
        MembershipRole $role = MembershipRole::Member,
        ?User $invitedBy = null,
    ): array {
        $email = Str::lower(mb_trim($email));

        $this->refuseExistingMember($email);

        return DB::transaction(function () use ($organization, $email, $role, $invitedBy): array {
            $invitation = Invitation::query()->where('email', $email)->first();

            if ($invitation instanceof Invitation && $invitation->isAcceptable()) {
                throw AlreadyInvited::to($email);
            }

            $invitation ??= new Invitation();

            $token = $invitation->issueToken();

            $invitation->fill([
                'organization_id' => $organization->id,
                'email' => $email,
                'role' => $role,
                'status' => InvitationStatus::Pending,
                'expires_at' => now()->addDays(config()->integer('organizations.invitations.expires_after_days')),
                'invited_by_user_id' => $invitedBy?->id,
                'accepted_at' => null,
                'accepted_by_user_id' => null,
                'revoked_at' => null,
                'revoked_by_user_id' => null,
            ])->save();

            $this->audit->handle($organization->id, AuditAction::InvitationSent, $invitation, [
                'email' => $email,
                'rank' => $role->value,
            ]);

            return ['invitation' => $invitation, 'token' => $token];
        });
    }

    /**
     * Refuse an address that is already inside the organization.
     *
     * Reads memberships under the resolved tenant, so the join to users stays
     * scoped: this asks "is one of *our* members this address", never "does this
     * address exist".
     */
    private function refuseExistingMember(string $email): void
    {
        $exists = Membership::query()
            ->where('status', MembershipStatus::Active)
            ->whereHas('user', fn (Builder $query): Builder => $query->where('email', $email))
            ->exists();

        if ($exists) {
            throw AlreadyMember::of($email);
        }
    }
}
