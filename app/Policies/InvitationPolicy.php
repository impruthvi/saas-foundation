<?php

declare(strict_types=1);

namespace App\Policies;

use App\Concerns\ChecksOrganizationPermissions;
use App\Enums\Permission;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use App\Tenancy\TenantContext;

/**
 * Who may see and manage an organization's invitations.
 *
 * Three questions in order: ownership, then membership standing, then the
 * permission. Each answers something the next cannot — `owner_id` survives a
 * drifted role assignment (D31), status is not expressible as a permission,
 * and the permission is the part M3 made configurable.
 *
 * The organization is the resolved tenant rather than an argument, because
 * these questions are only asked about the organization the request is acting
 * for. An invitation from anywhere else has already failed the global scope.
 */
final readonly class InvitationPolicy
{
    use ChecksOrganizationPermissions;

    public function __construct(
        private TenantContext $tenant,
        private MembershipRepository $memberships,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ViewMembers);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::InviteMembers);
    }

    /**
     * Resending is an update to the invitation, not a new one.
     */
    public function update(User $user): bool
    {
        return $this->allows($user, Permission::InviteMembers);
    }

    /**
     * Revoking. Declining is the recipient's verb and is authorized by holding
     * the token, not here.
     */
    public function delete(User $user): bool
    {
        return $this->allows($user, Permission::InviteMembers);
    }

    private function allows(User $user, Permission $permission): bool
    {
        $organization = $this->tenant->current();

        if (! $organization instanceof Organization) {
            return false;
        }

        if (! $this->memberships->activeMembership($user, $organization) instanceof Membership) {
            return false;
        }

        return $organization->owner_id === $user->id
            || $this->may($user, $permission);
    }
}
