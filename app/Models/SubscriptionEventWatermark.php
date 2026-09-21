<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Contracts\TenantOwned;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionEventWatermarkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * How recent the newest applied event for one Stripe subscription was.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $stripe_id
 * @property int $event_created_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 *
 * @method static SubscriptionEventWatermarkFactory factory($count = null, $state = [])
 * @method static Builder<static>|SubscriptionEventWatermark newModelQuery()
 * @method static Builder<static>|SubscriptionEventWatermark newQuery()
 * @method static Builder<static>|SubscriptionEventWatermark query()
 *
 * @mixin Model
 */
#[Fillable(['organization_id', 'stripe_id', 'event_created_at'])]
final class SubscriptionEventWatermark extends Model implements TenantOwned
{
    use BelongsToOrganization;

    /** @use HasFactory<SubscriptionEventWatermarkFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_created_at' => 'integer',
        ];
    }
}
