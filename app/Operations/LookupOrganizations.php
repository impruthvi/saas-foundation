<?php

declare(strict_types=1);

namespace App\Operations;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\MembershipRepository;

/**
 * Which organizations an email address is a member of.
 *
 * The organization lookup matches names, slugs and Stripe customers on the
 * organizations table itself. A member's address needs memberships, which are
 * tenant-owned, so it goes through the one repository allowed to read them
 * before an organization is known.
 */
final readonly class LookupOrganizations
{
    public function __construct(private MembershipRepository $memberships) {}

    /**
     * @return list<int>
     */
    public function byMemberEmail(string $email): array
    {
        $user = User::query()->where('email', mb_strtolower(mb_trim($email)))->first();

        if (! $user instanceof User) {
            return [];
        }

        return array_values($this->memberships->organizationsFor($user)
            ->map(fn (Organization $organization): int => $organization->id)
            ->all());
    }
}
