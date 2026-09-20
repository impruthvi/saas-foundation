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

/** Who may see or change billing for the resolved organization. */
final readonly class SubscriptionPolicy
{
    use ChecksOrganizationPermissions;

    public function __construct(
        private TenantContext $tenant,
        private MembershipRepository $memberships,
    ) {}

    public function viewAny(User $user): bool
    {
        $organization = $this->tenant->current();

        return $organization instanceof Organization
            && $this->memberships->activeMembership($user, $organization) instanceof Membership;
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, Permission::ManageBilling);
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
