<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

/**
 * Adds a user to an organization.
 *
 * M2's invitations end here once an invitation is accepted; until then this is
 * how a membership comes into being outside registration.
 */
final readonly class AddOrganizationMember
{
    public function handle(
        Organization $organization,
        User $user,
        MembershipRole $role = MembershipRole::Member,
    ): Membership {
        return Membership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
    }
}
