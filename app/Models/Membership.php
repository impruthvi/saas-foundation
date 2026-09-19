<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Contracts\TenantOwned;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The link between a user and an organization, carrying role and status.
 *
 * Tenant-owned like everything else, which means the question "which
 * organizations does this user belong to" cannot be asked of it directly: that
 * question is asked before any organization is resolved. It lives in
 * `App\Tenancy\MembershipRepository`, the one audited way around the scope (D22).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property MembershipRole $role
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

    /**
     * Members who can still actually run the organization.
     *
     * Rank alone is not enough: a suspended administrator holds the rank and
     * grants nothing, so counting them would let the last usable administrator
     * be removed.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function administrators(Builder $query): void
    {
        $query->where('role', MembershipRole::Admin)
            ->where('status', MembershipStatus::Active);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
        ];
    }
}
