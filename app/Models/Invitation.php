<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Contracts\TenantOwned;
use App\Enums\InvitationStatus;
use App\Enums\MembershipRole;
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
 * An offer of membership, made to an email address rather than to a user.
 *
 * Tenant-owned like every other row, which is what makes it awkward: the person
 * who reads an invitation is the one person guaranteed to be *outside* the
 * tenant. That read goes through `App\Tenancy\InvitationRepository`, the second
 * and last audited way around the scope (D27, after D22's first).
 *
 *   issued inside the tenant          read from outside it
 *   ────────────────────────          ────────────────────
 *   InviteOrganizationMember   ──►    InvitationRepository::findByToken()
 *   (scope applies normally)          (the audited door: both the scope and
 *                                      the retrieved guard stand down there)
 *
 * Two attributes are not what they look like:
 *
 * `token_hash` holds a digest. The token itself is generated once, handed to the
 * mailer, and never stored — `issueToken()` is the only place both halves exist
 * at the same time.
 *
 * `status` does not know about expiry. `Pending` and past `expires_at` is the
 * ordinary state of a stale invitation, and `isAcceptable()` is the question
 * callers actually mean.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $email
 * @property MembershipRole $role
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
     * The number of bytes of entropy behind a token.
     *
     * The token is the only thing standing between a stranger and membership of
     * an organization, so it is sized like a credential rather than like an id.
     */
    private const int TOKEN_BYTES = 48;

    /**
     * The digest a token is stored and looked up by.
     *
     * sha-256 rather than a password hash: the lookup has to be an indexed
     * equality match, and the input already carries full entropy, so the slow
     * hashing that protects a guessable password buys nothing here.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Mint a token, returning the plaintext and keeping only its digest.
     *
     * The plaintext is returned rather than stored because this is the one
     * moment it may exist: it goes into the email and nowhere else. Callers that
     * drop the return value have silently made the invitation unusable, which is
     * why this returns rather than assigning.
     */
    public function issueToken(): string
    {
        $token = Str::random(self::TOKEN_BYTES);

        $this->token_hash = self::hashToken($token);

        return $token;
    }

    /**
     * Whether the clock has run out on this invitation.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Whether this invitation could be taken right now, by the right person.
     *
     * Says nothing about who is asking — that is a separate refusal with its own
     * exception, because "too late" and "not for you" are different answers.
     */
    public function isAcceptable(): bool
    {
        return $this->status->isOpen() && ! $this->hasExpired();
    }

    /**
     * Whether the given address is the one this invitation names.
     *
     * Addresses are stored lower-cased, so a recipient who registers as
     * `B@Example.com` against an invitation to `b@example.com` is the same
     * person and is treated as such.
     */
    public function wasAddressedTo(string $email): bool
    {
        return $this->email === Str::lower($email);
    }

    /**
     * End this invitation without accepting it, stamping who ended it.
     *
     * Revoking and declining are the same transition reached from opposite
     * sides, so the transition lives here once and the two actions supply the
     * actor. Keeping it on the model also keeps `forceFill` off the callers:
     * the stamped columns are not fillable by an inviter.
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
     * Invitations still open and still in date.
     *
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
            'role' => MembershipRole::class,
            'status' => InvitationStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
