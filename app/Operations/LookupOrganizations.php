<?php

declare(strict_types=1);

namespace App\Operations;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * A member's address needs memberships, which are tenant-owned, so it goes through the
 * one repository allowed to read them before an organization is known.
 */
final readonly class LookupOrganizations
{
    public function __construct(private MembershipRepository $memberships) {}

    /**
     * By name, slug, Stripe customer id, or a member's address.
     *
     * @return list<int>
     */
    public function matching(string $search): array
    {
        return array_values(Organization::query()
            ->where(fn (Builder $query): Builder => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")
                ->orWhere('stripe_id', $search)
                ->orWhereIn('id', $this->byMemberEmail($search)))
            ->orderBy('id')
            ->pluck('id')
            ->all());
    }

    /**
     * @return list<int>
     */
    private function byMemberEmail(string $email): array
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
