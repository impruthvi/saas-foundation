<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Adds a user to an organization, with the rank they will hold and the role it implies.
 *
 * This is the only membership writer, keeping rank and its projected RBAC role
 * in one transaction under the correct tenant context.
 */
final readonly class AddOrganizationMember
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(
        Organization $organization,
        User $user,
        MembershipRole $role = MembershipRole::Member,
    ): Membership {
        return DB::transaction(fn (): Membership => $this->tenant->runForId(
            $organization->id,
            function () use ($organization, $user, $role): Membership {
                $membership = Membership::query()->create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'role' => $role,
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ]);

                $user->syncRoles([OrganizationRole::forRank($role)->value]);

                return $membership;
            },
        ));
    }
}
