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
     * Restores team scoping even when the package's cross-team detach throws, so a
     * failed deletion cannot widen authorization for later requests.
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
