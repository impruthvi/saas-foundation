<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Lookups that run before an organization is resolved; both the tenant scope and the
 * retrieved guard stand down.
 */
final readonly class MembershipRepository
{
    public function __construct(private TenantContext $tenant) {}

    /**
     * @return Collection<int, Organization>
     */
    public function organizationsFor(User $user): Collection
    {
        return $this->tenant->runWithoutTenant(fn (): Collection => Organization::query()
            ->whereIn('id', Membership::query()
                ->withoutTenantScope()
                ->where('user_id', $user->id)
                ->where('status', MembershipStatus::Active)
                ->select('organization_id'))
            ->orderByDesc('personal')
            ->orderBy('name')
            ->get());
    }

    public function activeMembership(User $user, Organization $organization): ?Membership
    {
        return $this->tenant->runWithoutTenant(fn (): ?Membership => Membership::query()
            ->withoutTenantScope()
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->where('status', MembershipStatus::Active)
            ->first());
    }

    /**
     * The organizations a user can work in now: the ones tenant resolution may pick and
     * the switcher may offer.
     *
     * @return Collection<int, Organization>
     */
    public function usableOrganizationsFor(User $user): Collection
    {
        return $this->organizationsFor($user)
            ->filter(fn (Organization $organization): bool => $organization->status->isUsable())
            ->values();
    }

    /**
     * @return Collection<int, Organization>
     */
    public function sharedOrganizationsOwnedBy(User $user): Collection
    {
        return $this->tenant->runWithoutTenant(fn (): Collection => Organization::query()
            ->where('owner_id', $user->id)
            ->whereIn('id', Membership::query()
                ->withoutTenantScope()
                ->where('user_id', '!=', $user->id)
                ->select('organization_id'))
            ->get());
    }
}
