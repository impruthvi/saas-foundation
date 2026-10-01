<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WebhookOutcome;
use Carbon\CarbonImmutable;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $stripe_event_id
 * @property string|null $type
 * @property string|null $stripe_customer_id
 * @property string|null $stripe_object_id
 * @property int|null $stripe_created_at
 * @property WebhookOutcome $outcome
 * @property string|null $outcome_reason
 * @property string|null $outcome_message
 * @property CarbonImmutable|null $applied_at
 * @property int $deliveries
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $first_received_at
 * @property CarbonImmutable $last_received_at
 *
 * @method static WebhookEventFactory factory($count = null, $state = [])
 * @method static Builder<static>|WebhookEvent newModelQuery()
 * @method static Builder<static>|WebhookEvent newQuery()
 * @method static Builder<static>|WebhookEvent query()
 *
 * @mixin Model
 */
#[Fillable([
    'stripe_event_id', 'type', 'stripe_customer_id', 'stripe_object_id', 'stripe_created_at',
    'outcome', 'outcome_reason', 'outcome_message', 'applied_at', 'deliveries', 'payload',
    'first_received_at', 'last_received_at',
])]
#[WithoutTimestamps]
final class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;

    use MassPrunable;

    public function canBeReplayed(): bool
    {
        return $this->applied_at === null && $this->outcome->isReplayable();
    }

    /**
     * Replayable events retain their Stripe payloads, so nothing is kept forever.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::query()->where(function (Builder $query): void {
            foreach (WebhookOutcome::cases() as $outcome) {
                $query->orWhere(fn (Builder $query): Builder => $query
                    ->where('outcome', $outcome)
                    ->where('last_received_at', '<', now()->subDays($outcome->retentionDays())));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => WebhookOutcome::class,
            'stripe_created_at' => 'integer',
            'deliveries' => 'integer',
            'payload' => 'array',
            'applied_at' => 'immutable_datetime',
            'first_received_at' => 'immutable_datetime',
            'last_received_at' => 'immutable_datetime',
        ];
    }
}
