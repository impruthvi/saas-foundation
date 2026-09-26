<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Exceptions\Memberships\LastAdministrator;
use App\Exceptions\Memberships\OwnerCannotBeRemoved;
use App\Models\Membership;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final readonly class RemoveOrganizationMember
{
    public function __construct(
        private TenantContext $tenant,
        private RecordAuditEvent $audit,
    ) {}

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

                $this->audit->handle($membership->organization_id, AuditAction::MemberRemoved, $membership, [
                    'user_id' => $membership->user_id,
                    'rank' => $membership->role->value,
                ]);
            },
        ));
    }

    /**
     * Only an active administrator can strand the organization. Locked and ordered like
     * the demotion path.
     */
    private function wouldLeaveNobodyInCharge(Membership $membership): bool
    {
        if (! $membership->isActiveAdministrator()) {
            return false;
        }

        // Ids rather than a count: PostgreSQL refuses FOR UPDATE with an aggregate.
        $activeAdministratorIds = Membership::query()
            ->lockForUpdate()
            ->administrators()
            ->orderBy('id')
            ->pluck('id');

        return $activeAdministratorIds->diff([$membership->id])->isEmpty();
    }
}
