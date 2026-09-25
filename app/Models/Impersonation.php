<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImpersonationEnd;
use Carbon\CarbonImmutable;
use Database\Factories\ImpersonationFactory;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bounded, reasoned period in which an operator acts as a user.
 *
 * @property int $id
 * @property int|null $operator_id
 * @property int|null $user_id
 * @property string $reason
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $ended_at
 * @property ImpersonationEnd|null $ended_by
 * @property-read User|null $operator
 * @property-read User|null $user
 *
 * @method static ImpersonationFactory factory($count = null, $state = [])
 * @method static Builder<static>|Impersonation newModelQuery()
 * @method static Builder<static>|Impersonation newQuery()
 * @method static Builder<static>|Impersonation query()
 *
 * @mixin Model
 */
#[Fillable(['operator_id', 'user_id', 'reason', 'started_at', 'expires_at', 'ended_at', 'ended_by'])]
#[WithoutTimestamps]
final class Impersonation extends Model
{
    /** @use HasFactory<ImpersonationFactory> */
    use HasFactory;

    /**
     * The only two keys an impersonated session carries besides the login.
     */
    public const string SESSION_KEY = 'impersonation_id';

    public const string IMPERSONATOR_SESSION_KEY = 'impersonator_id';

    /**
     * The live impersonation this session claims, if the claim still holds.
     *
     * A session naming a row that has ended, or naming an operator the row does
     * not, is treated as holding nothing.
     */
    public static function liveIn(Session $session): ?self
    {
        $id = $session->get(self::SESSION_KEY);

        if (! is_int($id)) {
            return null;
        }

        $impersonation = self::query()->with(['operator', 'user'])->whereKey($id)->whereNull('ended_at')->first();

        if ($impersonation === null || $impersonation->operator_id !== $session->get(self::IMPERSONATOR_SESSION_KEY)) {
            return null;
        }

        return $impersonation;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'ended_by' => ImpersonationEnd::class,
        ];
    }
}
