<?php

declare(strict_types=1);

namespace App\Actions;

use App\Entitlements\ResolveAllowance;
use App\Enums\MembershipRank;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Exceptions\Invitations\SeatLimitReached;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use LogicException;

/**
 * The only place a membership is created, so rank and its RBAC role start together in
 * one transaction.
 */
final readonly class AddOrganizationMember
{
    public function __construct(
        private TenantContext $tenant,
        private OwnerLocator $owners,
        private LocalResolver $resolver,
        private ResolveAllowance $allowances,
    ) {}

    public function handle(
        Organization $organization,
        User $user,
        MembershipRank $rank = MembershipRank::Member,
    ): Membership {
        return DB::transaction(fn (): Membership => $this->tenant->runForId(
            $organization->id,
            function () use ($organization, $user, $rank): Membership {
                if ($this->hasSeatLimit()) {
                    Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
                    $this->assertSeatAvailable($organization);
                }

                $membership = Membership::query()->create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'role' => $rank,
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ]);

                $user->syncRoles([OrganizationRole::forRank($rank)->value]);

                return $membership;
            },
        ));
    }

    public function assertSeatAvailable(Organization $organization): void
    {
        if (! $this->hasSeatLimit()) {
            return;
        }

        $this->tenant->runFor($organization, function () use ($organization): void {
            $limit = $this->allowances->handle($this->owners->reference($organization), 'seats');

            throw_if(is_bool($limit), LogicException::class, 'The seats feature must have a numeric allowance.');

            if ($limit !== null && Membership::query()->where('status', MembershipStatus::Active)->count() >= $limit) {
                throw SeatLimitReached::for($organization);
            }
        });
    }

    private function hasSeatLimit(): bool
    {
        return in_array('seats', $this->resolver->catalogFeatures(), true);
    }
}
