<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Handles membership lookups that run before an organization is resolved.
 * Both the tenant scope and retrieved guard must stand down for these queries.
 */
final readonly class MembershipRepository
{
    public function __construct(private TenantContext $tenant) {}

    /**
     * Every organization the user is an active member of, personal one first.
     *
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

    /**
     * The user's active membership of one organization, if there is one.
     */
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
     * The organization to resolve when the user has not chosen one.
     *
     * Prefer the user's personal organization when the session names none.
     */
    public function defaultFor(User $user): ?Organization
    {
        return $this->organizationsFor($user)->first();
    }

    /**
     * Whether the user still solely owns an organization with other members.
     *
     * @return Collection<int, Organization>
     */
    public function sharedOrganizationsOwnedBy(User $user): Collection
    {
        return $this->tenant->runWithoutTenant(fn (): Collection => Organization::query()
            ->where('owner_id', $user->id)
            ->where('personal', false)
            ->whereIn('id', Membership::query()
                ->withoutTenantScope()
                ->where('user_id', '!=', $user->id)
                ->select('organization_id'))
            ->get());
    }
}
