<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\InvitationStatus;
use App\Enums\MembershipRank;
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
 * Reuses the row rather than inserting, because (organization_id, email) is unique;
 * re-inviting rotates the token and invalidates the old one. The plaintext token is
 * returned once and never stored.
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
        MembershipRank $rank = MembershipRank::Member,
        ?User $invitedBy = null,
    ): array {
        $email = Str::lower(mb_trim($email));

        $this->refuseExistingMember($email);

        return DB::transaction(function () use ($organization, $email, $rank, $invitedBy): array {
            $invitation = Invitation::query()->where('email', $email)->first();

            if ($invitation instanceof Invitation && $invitation->isAcceptable()) {
                throw AlreadyInvited::to($email);
            }

            $invitation ??= new Invitation();

            $token = $invitation->issueToken();

            $invitation->fill([
                'organization_id' => $organization->id,
                'email' => $email,
                'role' => $rank,
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
                'rank' => $rank->value,
            ]);

            return ['invitation' => $invitation, 'token' => $token];
        });
    }

    /**
     * Reads memberships under the resolved tenant, so it only asks whether one of this
     * organization's members has the address.
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
