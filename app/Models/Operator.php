<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OperatorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person allowed into the admin console.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $granted_by
 * @property string $reason
 * @property CarbonImmutable $granted_at
 * @property-read User $user
 *
 * @method static OperatorFactory factory($count = null, $state = [])
 * @method static Builder<static>|Operator newModelQuery()
 * @method static Builder<static>|Operator newQuery()
 * @method static Builder<static>|Operator query()
 *
 * @mixin Model
 */
#[Fillable(['user_id', 'granted_by', 'reason', 'granted_at'])]
#[WithoutTimestamps]
final class Operator extends Model
{
    /** @use HasFactory<OperatorFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'immutable_datetime',
        ];
    }
}
