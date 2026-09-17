<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use App\Tenancy\TenantContext;

/**
 * Who may see and manage an organization's invitations.
 *
 * The seam `spatie/laravel-permission` slots into at M3. What changes then is
 * *where the answer comes from*, not the shape of these methods: D23 is explicit
 * that `memberships.role` means rank and must never become a second permission
 * store, so when the package arrives it owns `can()` and this class asks it.
 *
 * Until then the rule is deliberately small — the owner, or a member ranked
 * Admin. Ownership is read from `organizations.owner_id`, the one writable fact,
 * rather than inferred from a role that has no Owner case.
 *
 * The organization is the resolved tenant rather than an argument, because these
 * questions are only ever asked about the organization the request is acting
 * for. An invitation from anywhere else has already failed the global scope.
 */
final readonly class InvitationPolicy
{
    public function __construct(
        private TenantContext $tenant,
        private MembershipRepository $memberships,
    ) {}

    /**
     * Any active member may see who else is in, and who has been asked.
     */
    public function viewAny(User $user): bool
    {
        $organization = $this->tenant->current();

        return $organization instanceof Organization
            && $this->memberships->activeMembership($user, $organization) instanceof Membership;
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    /**
     * Resending is an update to the invitation, not a new one.
     */
    public function update(User $user): bool
    {
        return $this->manages($user);
    }

    /**
     * Revoking. Declining is the recipient's verb and is not authorized here —
     * it is authorized by holding the token.
     */
    public function delete(User $user): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        $organization = $this->tenant->current();

        if (! $organization instanceof Organization) {
            return false;
        }

        if ($organization->owner_id === $user->id) {
            return true;
        }

        $membership = $this->memberships->activeMembership($user, $organization);

        return $membership instanceof Membership && $membership->role === MembershipRole::Admin;
    }
}
