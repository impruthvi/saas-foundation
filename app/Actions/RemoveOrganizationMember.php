<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\Memberships\LastAdministrator;
use App\Exceptions\Memberships\OwnerCannotBeRemoved;
use App\Models\Membership;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Takes somebody out of an organization.
 *
 * The other half of M2: invitations built the way in and left no way out.
 *
 *   removing the owner? ──────────────► OwnerCannotBeRemoved
 *              │ no
 *              ▼
 *   the last active administrator? ───► LastAdministrator
 *              │ no
 *              ▼
 *   delete the membership ──► the deleted hook revokes the role and any
 *                             direct permissions, in this organization only
 *
 * The two refusals are separate classes because they have different remedies:
 * one says transfer ownership, the other says promote somebody first.
 */
final readonly class RemoveOrganizationMember
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Membership $membership): void
    {
        DB::transaction(fn () => $this->tenant->runForId(
            $membership->organization_id,
            function () use ($membership): void {
                $organization = Organization::query()->findOrFail($membership->organization_id);

                throw_if(
                    $organization->owner_id === $membership->user_id,
                    OwnerCannotBeRemoved::of($organization),
                );

                throw_if(
                    $this->wouldLeaveNobodyInCharge($membership),
                    LastAdministrator::of($organization),
                );

                $membership->delete();
            },
        ));
    }

    /**
     * Removing a suspended administrator takes nothing away, so only an active
     * one can strand the organization. Ordered and locked for the same reason
     * the demotion path is: two administrators removing each other at the same
     * moment must not both read "there are two of us".
     */
    private function wouldLeaveNobodyInCharge(Membership $membership): bool
    {
        if (! $membership->isActiveAdministrator()) {
            return false;
        }

        // The ids rather than a count or an exists: PostgreSQL refuses FOR
        // UPDATE alongside an aggregate, and holding the rows is the point.
        $remaining = Membership::query()
            ->lockForUpdate()
            ->administrators()
            ->whereKeyNot($membership->id)
            ->orderBy('id')
            ->pluck('id');

        return $remaining->isEmpty();
    }
}
