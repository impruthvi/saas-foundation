<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;

/**
 * Not tenant-owned: the table has no organization column and reads go through a scoped
 * subscription. Querying it directly crosses tenants.
 *
 * @property int $id
 * @property int $subscription_id
 * @property string $stripe_id
 * @property string $stripe_product
 * @property string $stripe_price
 * @property string|null $meter_id
 * @property int|null $quantity
 * @property string|null $meter_event_name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Subscription $subscription
 *
 * @method static Builder<static>|SubscriptionItem newModelQuery()
 * @method static Builder<static>|SubscriptionItem newQuery()
 * @method static Builder<static>|SubscriptionItem query()
 *
 * @mixin Model
 */
final class SubscriptionItem extends CashierSubscriptionItem {}
