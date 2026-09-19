<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Exceptions\Memberships\LastAdministrator;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Promotes or demotes a member, moving rank and the role it implies together.
 *
 * Exists as its own action from M3 because changing rank became two writes.
 * Doing it as one statement on the members screen is how the projection (D31)
 * starts drifting, and the drift is silent: the row says Admin and the person
 * cannot invite anyone.
 *
 *   already at this rank? ──yes──► nothing to do, return the membership
 *              │ no
 *              ▼
 *   demoting the last administrator? ──yes──► LastAdministrator
 *              │ no
 *              ▼
 *   rank ──► role, one transaction, inside the organization's own tenant
 *
 * The count is taken under `lockForUpdate`, so two administrators demoting
 * each other at the same moment cannot both read "there are two of us" and
 * both proceed. SQLite ignores the lock, so the concurrent case is proven
 * against PostgreSQL only — see the test.
 */
final readonly class ChangeOrganizationMemberRole
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Membership $membership, MembershipRole $role): Membership
    {
        if ($membership->role === $role) {
            return $membership;
        }

        return DB::transaction(fn (): Membership => $this->tenant->runForId(
            $membership->organization_id,
            function () use ($membership, $role): Membership {
                $this->assertAnAdministratorRemains($membership, $role);

                $membership->forceFill(['role' => $role])->save();

                // Loaded by key rather than through the relation: lazy loading
                // is prevented application-wide, and the membership handed in
                // may have arrived without its user.
                User::query()->findOrFail($membership->user_id)
                    ->syncRoles([OrganizationRole::forRank($role)->value]);

                return $membership->refresh();
            },
        ));
    }

    /**
     * Refuse a demotion that would leave nobody able to run the organization.
     *
     * @throws LastAdministrator
     */
    private function assertAnAdministratorRemains(Membership $membership, MembershipRole $role): void
    {
        if ($role === MembershipRole::Admin || $membership->role !== MembershipRole::Admin) {
            return;
        }

        // The ids rather than a count: PostgreSQL refuses FOR UPDATE alongside
        // an aggregate, and the point of the lock is to hold the rows anyway,
        // so that two administrators demoting each other at the same moment
        // cannot both read "there are two of us" and both proceed.
        $administrators = Membership::query()
            ->lockForUpdate()
            ->where('role', MembershipRole::Admin)
            ->where('status', MembershipStatus::Active)
            ->pluck('id');

        if ($administrators->count() > 1) {
            return;
        }

        throw LastAdministrator::of(
            Organization::query()->findOrFail($membership->organization_id),
        );
    }
}
