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
 * Row rules come from Membership::mayBeRemovedFrom(), which the members screen also
 * calls, so buttons and policy agree. Removing yourself is not special-cased; the
 * last-administrator rule covers it.
 */
final readonly class MembershipPolicy
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

    public function manage(User $user): bool
    {
        return $this->allows($user, Permission::ManageMembers);
    }

    public function delete(User $user, Membership $membership): bool
    {
        return $this->mayManage($user, $membership);
    }

    /**
     * A rank change is refused for exactly the rows a removal is: the owner, and the
     * last active administrator.
     */
    public function update(User $user, Membership $membership): bool
    {
        return $this->mayManage($user, $membership);
    }

    private function mayManage(User $user, Membership $membership): bool
    {
        $organization = $this->tenant->current();

        if (! $organization instanceof Organization) {
            return false;
        }

        return $this->allows($user, Permission::ManageMembers)
            && $membership->mayBeRemovedFrom($organization, $this->otherActiveAdministrators($membership));
    }

    private function otherActiveAdministrators(Membership $membership): int
    {
        return Membership::query()
            ->administrators()
            ->whereKeyNot($membership->id)
            ->count();
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
