<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Contracts\TenantOwned;
use App\Enums\MembershipRole;
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

    public function isActiveAdministrator(): bool
    {
        return $this->role === MembershipRole::Admin
            && $this->status === MembershipStatus::Active;
    }

    /**
     * Whether this membership may be ended at all.
     *
     * The row-local half of the removal rules, in one place so the policy, the
     * members screen and the action cannot disagree about it. The count is
     * passed in because "is anyone else still running this" spans every page.
     */
    public function mayBeRemovedFrom(Organization $organization, int $otherActiveAdministrators): bool
    {
        if ($organization->owner_id === $this->user_id) {
            return false;
        }

        return ! $this->isActiveAdministrator() || $otherActiveAdministrators > 0;
    }

    /**
     * Revoke the organization's grants when the membership goes.
     *
     * `model_has_roles` is keyed to organizations and users, never to this
     * table, so deleting a membership leaves the assignment behind and the
     * removed person keeps everything it granted. The revocation is here rather
     * than only in the action so that a later caller cannot forget it, and it
     * names the organization explicitly rather than trusting whichever tenant
     * happens to be resolved.
     *
     * Direct permissions go too. Nothing grants them today, but
     * `model_has_permissions` carries the same team key and would outlive the
     * membership in exactly the same way.
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
