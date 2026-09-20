<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FailedWebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A billing event that was acknowledged to Stripe but never applied.
 *
 * @property int $id
 * @property string|null $stripe_event_id
 * @property string|null $type
 * @property string|null $stripe_customer_id
 * @property array<string, mixed> $payload
 * @property string $reason
 * @property string $message
 * @property CarbonImmutable $created_at
 *
 * @method static FailedWebhookEventFactory factory($count = null, $state = [])
 * @method static Builder<static>|FailedWebhookEvent newModelQuery()
 * @method static Builder<static>|FailedWebhookEvent newQuery()
 * @method static Builder<static>|FailedWebhookEvent query()
 *
 * @mixin Model
 */
#[Fillable(['stripe_event_id', 'type', 'stripe_customer_id', 'payload', 'reason', 'message', 'created_at'])]
#[WithoutTimestamps]
final class FailedWebhookEvent extends Model
{
    /** @use HasFactory<FailedWebhookEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
