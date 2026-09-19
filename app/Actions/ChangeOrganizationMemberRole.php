<?php

declare(strict_types=1);

namespace App\Actions;

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
 * Promotes or demotes a member, moving rank and the role it implies together.
 *
 * The count is taken under `lockForUpdate`, so two administrators demoting
 * each other at the same moment cannot both read "there are two of us" and
 * both proceed.
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
                $this->assertTheDemotionIsSafe($membership, $role);

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
     * Refuse a demotion that would strand the organization.
     *
     * Two refusals, and the order matters: the owner is refused whatever the
     * administrator count says, because ownership and rank must not disagree.
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

        // Who *remains*, not who is there: this membership is excluded, so
        // demoting a suspended administrator is not refused for the sake of an
        // administrator who was already granting nothing.
        //
        // The ids rather than a count, because PostgreSQL refuses FOR UPDATE
        // alongside an aggregate. Ordered, so two concurrent changes take the
        // row locks in the same sequence and queue instead of deadlocking.
        $remaining = Membership::query()
            ->lockForUpdate()
            ->administrators()
            ->whereKeyNot($membership->id)
            ->orderBy('id')
            ->pluck('id');

        throw_if($remaining->isEmpty(), LastAdministrator::of($organization));
    }
}
