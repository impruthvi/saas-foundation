<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Contracts\TenantOwned;
use App\Enums\InvitationStatus;
use App\Enums\MembershipRank;
use App\Exceptions\Invitations\InvitationAlreadyAccepted;
use App\Exceptions\Invitations\InvitationDeclined;
use App\Exceptions\Invitations\InvitationRefused;
use App\Exceptions\Invitations\InvitationRevoked;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Only the token's digest is stored.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $email
 * @property MembershipRank $role
 * @property string $token_hash
 * @property InvitationStatus $status
 * @property CarbonImmutable $expires_at
 * @property int|null $invited_by_user_id
 * @property CarbonImmutable|null $accepted_at
 * @property int|null $accepted_by_user_id
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 * @property-read User|null $invitedBy
 * @property-read User|null $acceptedBy
 * @property-read User|null $revokedBy
 *
 * @method static InvitationFactory factory($count = null, $state = [])
 * @method static Builder<static>|Invitation newModelQuery()
 * @method static Builder<static>|Invitation newQuery()
 * @method static Builder<static>|Invitation query()
 * @method static Builder<static>|Invitation pending()
 *
 * @mixin Model
 */
#[Fillable([
    'organization_id',
    'email',
    'role',
    'token_hash',
    'status',
    'expires_at',
    'invited_by_user_id',
    'accepted_at',
    'accepted_by_user_id',
    'revoked_at',
    'revoked_by_user_id',
])]
final class Invitation extends Model implements TenantOwned
{
    use BelongsToOrganization;

    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    /**
     * Sized like a credential: the token alone admits a stranger to an organization.
     */
    private const int TOKEN_BYTES = 48;

    /**
     * sha-256, not a password hash: the lookup is an indexed equality match and the
     * token already has full entropy.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Returns the plaintext because this is the only moment it exists; dropping the
     * return value makes the invitation unusable.
     */
    public function issueToken(): string
    {
        $token = Str::random(self::TOKEN_BYTES);

        $this->token_hash = self::hashToken($token);

        return $token;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Refuses anything but a pending invitation. Callers that change the row check
     * freshLocked(), so a concurrent close cannot slip between the check and the write.
     *
     * @throws InvitationRefused
     */
    public function assertOpen(): void
    {
        match ($this->status) {
            InvitationStatus::Accepted => throw InvitationAlreadyAccepted::make(),
            InvitationStatus::Revoked => throw InvitationRevoked::make(),
            InvitationStatus::Declined => throw InvitationDeclined::make(),
            InvitationStatus::Pending => null,
        };
    }

    /**
     * Read under the invitation's own organization, so it works with no tenant resolved;
     * the write that follows still runs under the caller's tenant and its guard.
     */
    public function freshLocked(): self
    {
        return resolve(TenantContext::class)->runForId(
            $this->organization_id,
            fn (): self => self::query()->lockForUpdate()->findOrFail($this->id),
        );
    }

    /** Says nothing about the recipient, which has its own refusal. */
    public function isAcceptable(): bool
    {
        return $this->status->isOpen() && ! $this->hasExpired();
    }

    public function wasAddressedTo(string $email): bool
    {
        return $this->email === Str::lower($email);
    }

    /**
     * Keeps forceFill off the callers: the stamped columns are not fillable by an
     * inviter.
     *
     * @param  array<string, mixed>  $stamps
     */
    public function close(InvitationStatus $status, array $stamps = []): self
    {
        $this->forceFill(['status' => $status, ...$stamps])->save();

        return $this;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', InvitationStatus::Pending)
            ->where('expires_at', '>', now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MembershipRank::class,
            'status' => InvitationStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
