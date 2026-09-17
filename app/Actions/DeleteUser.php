<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\OwnershipTransferRequired;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use Illuminate\Support\Facades\DB;

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

        DB::transaction(function () use ($user): void {
            $this->memberships->organizationsFor($user)
                ->filter(fn (Organization $organization): bool => $organization->owner_id === $user->id)
                ->each(fn (Organization $organization): ?bool => $organization->delete());

            $user->delete();
        });
    }
}
