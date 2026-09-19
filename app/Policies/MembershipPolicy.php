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
 * Who may manage who else is in the organization.
 *
 * Two questions layered: may this person manage members at all, and may *this
 * membership* be touched. The first is the shared ladder; the second is
 * `Membership::mayBeRemovedFrom()`, which the members screen calls with the
 * same arguments so the buttons and the policy cannot disagree.
 *
 * Removing yourself is allowed, and deliberately not special-cased: an
 * administrator leaving is the same question as an administrator being removed,
 * and the last-administrator rule already covers the case that matters.
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

    /**
     * Whether this person manages members at all, before any particular row is
     * considered. The members screen asks once and derives each row from it.
     */
    public function manage(User $user): bool
    {
        return $this->allows($user, Permission::ManageMembers);
    }

    public function delete(User $user, Membership $membership): bool
    {
        $organization = $this->tenant->current();

        if (! $organization instanceof Organization) {
            return false;
        }

        return $this->allows($user, Permission::ManageMembers)
            && $membership->mayBeRemovedFrom($organization, $this->otherActiveAdministrators($membership));
    }

    public function update(User $user, Membership $membership): bool
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
