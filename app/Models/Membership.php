<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Contracts\TenantOwned;
use App\Enums\MembershipRank;
use App\Enums\MembershipStatus;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property MembershipRank $role
 * @property MembershipStatus $status
 * @property CarbonImmutable|null $joined_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 * @property-read User $user
 *
 * @method static MembershipFactory factory($count = null, $state = [])
 * @method static Builder<static>|Membership newModelQuery()
 * @method static Builder<static>|Membership newQuery()
 * @method static Builder<static>|Membership query()
 *
 * @mixin Model
 */
#[Fillable(['organization_id', 'user_id', 'role', 'status', 'joined_at'])]
final class Membership extends Model implements TenantOwned
{
    use BelongsToOrganization;

    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActiveAdministrator(): bool
    {
        return $this->role === MembershipRank::Admin
            && $this->status === MembershipStatus::Active;
    }

    /**
     * The row-local half of the removal rules, shared by the policy, the members screen
     * and the action. The count is passed in because it spans every page.
     */
    public function mayBeRemovedFrom(Organization $organization, int $otherActiveAdministrators): bool
    {
        if ($organization->owner_id === $this->user_id) {
            return false;
        }

        return ! $this->isActiveAdministrator() || $otherActiveAdministrators > 0;
    }

    /**
     * model_has_roles is keyed to organization and user, not to this row, so deleting a
     * membership would leave its grants behind. Revoked here so no caller can forget,
     * naming the organization explicitly. Direct permissions go too.
     */
    protected static function booted(): void
    {
        self::deleted(function (Membership $membership): void {
            resolve(TenantContext::class)->runForId(
                $membership->organization_id,
                function () use ($membership): void {
                    User::query()->find($membership->user_id)
                        ?->syncRoles([])
                        ->syncPermissions([]);
                },
            );
        });
    }

    /**
     * A suspended administrator holds the rank and grants nothing, so it does not
     * count.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function administrators(Builder $query): void
    {
        $query->where('role', MembershipRank::Admin)
            ->where('status', MembershipStatus::Active);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MembershipRank::class,
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
        ];
    }
}
