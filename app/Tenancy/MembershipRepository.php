<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The one query that cannot be scoped, in the one place allowed to make it.
 *
 * "Which organizations does this user belong to" is asked before any
 * organization is resolved — by the middleware that resolves one, by the
 * switcher, and by the check that decides whether a solo user sees a switcher
 * at all. Memberships are tenant-owned like everything else, so answering it
 * means stepping around the scope; D22 puts every such step here, and
 * `tests/Unit/TenantScopingTest.php` fails the build if one appears elsewhere.
 *
 * Both the global scope and the retrieved guard have to be stood down, which is
 * why the work runs inside `runWithoutTenant()` rather than only reaching for
 * `withoutTenantScope()`: a row from another organization would otherwise raise
 * `CrossTenantAccess` on arrival.
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
     * Their personal organization, which D1 guarantees exists from registration.
     */
    public function defaultFor(User $user): ?Organization
    {
        return $this->organizationsFor($user)->first();
    }

    /**
     * Whether the user still solely owns an organization with other members.
     *
     * The question account deletion has to ask before it does anything (D25).
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
