<?php

declare(strict_types=1);

namespace App\Policies;

use App\Concerns\ChecksOrganizationPermissions;
use App\Enums\Permission;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use App\Tenancy\TenantContext;

final readonly class ProjectPolicy
{
    use ChecksOrganizationPermissions;

    public function __construct(
        private TenantContext $tenant,
        private MembershipRepository $memberships,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ManageProjects);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ManageProjects);
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
