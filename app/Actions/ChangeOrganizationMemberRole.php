<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\MembershipRole;
use App\Enums\OrganizationRole;
use App\Exceptions\Memberships\LastAdministrator;
use App\Exceptions\Memberships\OwnerCannotBeDemoted;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The administrator count is taken under lockForUpdate, so two administrators demoting
 * each other cannot both proceed.
 */
final readonly class ChangeOrganizationMemberRole
{
    public function __construct(
        private TenantContext $tenant,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Membership $membership, MembershipRole $role): Membership
    {
        if ($membership->role === $role) {
            return $membership;
        }

        return DB::transaction(fn (): Membership => $this->tenant->runForId(
            $membership->organization_id,
            function () use ($membership, $role): Membership {
                $this->assertTheDemotionIsSafe($membership, $role);

                $previous = $membership->role;

                $membership->forceFill(['role' => $role])->save();

                // Loaded by key: lazy loading is prevented and the membership may
                // arrive without its user.
                User::query()->findOrFail($membership->user_id)
                    ->syncRoles([OrganizationRole::forRank($role)->value]);

                $this->audit->handle($membership->organization_id, AuditAction::MemberRankChanged, $membership, [
                    'user_id' => $membership->user_id,
                    'from' => $previous->value,
                    'to' => $role->value,
                ]);

                return $membership->refresh();
            },
        ));
    }

    /**
     * The owner is refused whatever the administrator count says; ownership and rank
     * must not disagree.
     *
     * @throws OwnerCannotBeDemoted|LastAdministrator
     */
    private function assertTheDemotionIsSafe(Membership $membership, MembershipRole $role): void
    {
        if ($role === MembershipRole::Admin) {
            return;
        }

        $organization = Organization::query()->findOrFail($membership->organization_id);

        throw_if(
            $organization->owner_id === $membership->user_id,
            OwnerCannotBeDemoted::of($organization),
        );

        if ($membership->role !== MembershipRole::Admin) {
            return;
        }

        // Counts who remains, so demoting a suspended administrator is not refused.
        // Ids rather than a count: PostgreSQL refuses FOR UPDATE with an aggregate.
        // Ordered so concurrent changes lock in the same sequence instead of
        // deadlocking.
        $remaining = Membership::query()
            ->lockForUpdate()
            ->administrators()
            ->whereKeyNot($membership->id)
            ->orderBy('id')
            ->pluck('id');

        throw_if($remaining->isEmpty(), LastAdministrator::of($organization));
    }
}
