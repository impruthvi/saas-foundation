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
 * The only membership writer, so rank and its RBAC role change in one transaction.
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
