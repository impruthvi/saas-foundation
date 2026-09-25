<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\MembershipRole;
use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Hands an organization to a different owner.
 *
 * The new owner must already be active, and personal organizations cannot be
 * transferred. The outgoing owner keeps their membership and Admin rank.
 */
final readonly class TransferOrganizationOwnership
{
    public function __construct(
        private MembershipRepository $memberships,
        private TenantContext $tenant,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Organization $organization, User $newOwner): Organization
    {
        throw_if($organization->isPersonal(), InvalidArgumentException::class, 'A personal organization cannot be transferred.');

        throw_if(! $this->memberships->activeMembership($newOwner, $organization) instanceof Membership, InvalidArgumentException::class, 'Ownership can only be transferred to an active member.');

        return DB::transaction(fn (): Organization => $this->tenant->runFor($organization, function () use ($organization, $newOwner): Organization {
            $previousOwnerId = $organization->owner_id;

            $organization->forceFill(['owner_id' => $newOwner->id])->save();

            $organization->memberships()
                ->where('user_id', $newOwner->id)
                ->update(['role' => MembershipRole::Admin]);

            // The bulk update fires no model events, so update the projected
            // role explicitly. Use syncRoles rather than
            // assignRole: the new owner was a member a line ago, and a promotion
            // that accumulates leaves them holding both roles.
            $newOwner->syncRoles([OrganizationRole::forRank(MembershipRole::Admin)->value]);

            $this->audit->handle($organization->id, AuditAction::OwnershipTransferred, $organization, [
                'from_user_id' => $previousOwnerId,
                'to_user_id' => $newOwner->id,
            ]);

            return $organization->refresh();
        }));
    }
}
