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
 * M2's invitations end here once an invitation is accepted, and from M3 so does
 * organization creation — this is the one place a membership comes into being,
 * which is what makes it the one place a role assignment does.
 *
 *   rank written to memberships.role        ◄── the writable fact (D23)
 *              │
 *              │  OrganizationRole::forRank()
 *              ▼
 *   role written to model_has_roles         ◄── a projection, never written alone (D31)
 *
 * Both in one transaction, because a membership without its assignment is a
 * member who can do nothing and is told why by nothing. Inside `runForId()`,
 * because the assignment is scoped to the organization the package currently
 * has resolved and this action is called from places that have resolved none —
 * registration among them.
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
