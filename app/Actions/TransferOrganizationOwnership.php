<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MembershipRole;
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
 * Load-bearing from the day it is written: it is the remedy offered when
 * account deletion is refused (D25). The new owner has to already be an active
 * member, and a personal organization cannot be handed anywhere — it is one
 * user's own, and D1 has no notion of a personal organization that outlives the
 * person.
 *
 * The outgoing owner keeps their membership and their Admin rank. Ownership
 * itself moves as a single column, because ownership is a single fact (D23).
 */
final readonly class TransferOrganizationOwnership
{
    public function __construct(
        private MembershipRepository $memberships,
        private TenantContext $tenant,
    ) {}

    public function handle(Organization $organization, User $newOwner): Organization
    {
        throw_if($organization->isPersonal(), InvalidArgumentException::class, 'A personal organization cannot be transferred.');

        throw_if(! $this->memberships->activeMembership($newOwner, $organization) instanceof Membership, InvalidArgumentException::class, 'Ownership can only be transferred to an active member.');

        return DB::transaction(fn (): Organization => $this->tenant->runFor($organization, function () use ($organization, $newOwner): Organization {
            $organization->forceFill(['owner_id' => $newOwner->id])->save();

            $organization->memberships()
                ->where('user_id', $newOwner->id)
                ->update(['role' => MembershipRole::Admin]);

            return $organization->refresh();
        }));
    }
}
