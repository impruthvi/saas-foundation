<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\MembershipRank;
use App\Enums\OrganizationRole;
use App\Jobs\SyncStripeCustomerContact;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
                ->update(['role' => MembershipRank::Admin]);

            // The bulk update fires no model events, so the role is synced explicitly.
            // syncRoles, not assignRole, so the promoted member does not keep both
            // roles.
            $newOwner->syncRoles([OrganizationRole::forRank(MembershipRank::Admin)->value]);

            $this->audit->handle($organization->id, AuditAction::OwnershipTransferred, $organization, [
                'from_user_id' => $previousOwnerId,
                'to_user_id' => $newOwner->id,
            ]);

            if ($organization->hasStripeId()) {
                dispatch(new SyncStripeCustomerContact($organization->id))->afterCommit();
            }

            return $organization->refresh();
        }));
    }
}
