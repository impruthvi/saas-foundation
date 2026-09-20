<?php

declare(strict_types=1);

namespace App\Actions;

use App\Billing\BillingFacts;
use App\Exceptions\BillingMustBeResolved;
use App\Exceptions\OwnershipTransferRequired;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deletes a user account without orphaning anything that belongs to it.
 *
 * Shared organizations require ownership transfer first. The permission
 * package temporarily disables team scoping while revoking cross-team grants,
 * so this action restores the previous setting even when deletion fails.
 */
final readonly class DeleteUser
{
    public function __construct(
        private MembershipRepository $memberships,
        private BillingFacts $billing,
        private TenantContext $tenant,
    ) {}

    public function handle(User $user): void
    {
        $shared = $this->memberships->sharedOrganizationsOwnedBy($user);

        if ($shared->isNotEmpty()) {
            throw OwnershipTransferRequired::before($shared);
        }

        $ownedOrganizations = $this->memberships->organizationsFor($user)
            ->filter(fn (Organization $organization): bool => $organization->owner_id === $user->id);

        foreach ($ownedOrganizations as $organization) {
            $subscription = $this->tenant->runFor(
                $organization,
                fn (): ?Subscription => $this->billing->openSubscription($organization),
            );

            if ($subscription instanceof Subscription) {
                throw BillingMustBeResolved::for($organization, $subscription->ends_at);
            }
        }

        $this->keepingTeamScopingOn(fn () => DB::transaction(function () use ($ownedOrganizations, $user): void {
            $ownedOrganizations->each(fn (Organization $organization): ?bool => $organization->delete());

            $user->delete();
        }));
    }

    /**
     * Run the deletion, and leave team scoping however it was found.
     *
     * The package's `deleting` hook disables it for the duration of its
     * cross-team detach. This restores it even when that detach raises, so a
     * failed account deletion cannot quietly widen authorization for every
     * request the process serves afterwards.
     */
    private function keepingTeamScopingOn(Closure $work): void
    {
        $permissions = resolve(PermissionRegistrar::class);
        $scoped = $permissions->teams;

        try {
            $work();
        } finally {
            $permissions->teams = $scoped;
        }
    }
}
