<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\OwnershipTransferRequired;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use Closure;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deletes a user account without orphaning anything that belongs to it.
 *
 *   sole owner of a shared organization? ──yes──► refuse, name the remedy
 *                 │ no
 *                 ▼
 *   delete the organizations they alone own (memberships and projects cascade)
 *                 │
 *                 ▼
 *   delete the user (their remaining memberships cascade)
 *
 * Before M1 this was a one-line delete. From M4 the organization holds a live
 * subscription, so silent orphaning would be discovered by whoever is still
 * being billed (D25). `organizations.owner_id` restricts on delete, so the
 * database refuses the orphan even if this rule is ever bypassed.
 *
 * Deleting the row also revokes every role the user held, in every
 * organization at once: `HasRoles` registers a `deleting` hook that turns team
 * scoping off, detaches across all teams, and turns it back on. That crossing
 * is correct — an account being closed should not keep grants anywhere — but
 * it is a crossing, and it is the reason the suite needs a named door to let
 * the resulting unscoped deletes past the query guard.
 *
 * The hook turns scoping back on as its last statement rather than in a
 * `finally`, so a detach that throws leaves it off for the rest of the
 * process. On a queue worker that is every later `can()` answered with the
 * organization ignored, which is the widest failure this milestone can
 * produce. Restoring it here costs one line and does not depend on the
 * package changing.
 */
final readonly class DeleteUser
{
    public function __construct(private MembershipRepository $memberships) {}

    public function handle(User $user): void
    {
        $shared = $this->memberships->sharedOrganizationsOwnedBy($user);

        if ($shared->isNotEmpty()) {
            throw OwnershipTransferRequired::before($shared);
        }

        $this->keepingTeamScopingOn(fn () => DB::transaction(function () use ($user): void {
            $this->memberships->organizationsFor($user)
                ->filter(fn (Organization $organization): bool => $organization->owner_id === $user->id)
                ->each(fn (Organization $organization): ?bool => $organization->delete());

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
