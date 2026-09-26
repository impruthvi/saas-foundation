<?php

declare(strict_types=1);

namespace App\Policies;

use App\Concerns\ChecksOrganizationPermissions;
use App\Enums\Permission;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use App\Tenancy\TenantContext;

/**
 * Asks about the resolved tenant only; an invitation from elsewhere has already failed
 * the global scope.
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

    public function update(User $user): bool
    {
        return $this->allows($user, Permission::InviteMembers);
    }

    public function delete(User $user): bool
    {
        return $this->allows($user, Permission::InviteMembers);
    }

    private function tenant(): TenantContext
    {
        return $this->tenant;
    }

    private function memberships(): MembershipRepository
    {
        return $this->memberships;
    }
}
