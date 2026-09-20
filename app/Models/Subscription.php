<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Contracts\TenantOwned;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * An organization's ongoing commercial relationship to a plan, as last reported
 * by Stripe.
 *
 * Cashier's own model is unscoped. This one carries the tenant scope, so reading
 * a subscription without a resolved organization raises rather than answering for
 * whichever row matched. Webhook processing satisfies that by resolving the
 * organization from the Stripe customer before any write.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $type
 * @property string $stripe_id
 * @property string $stripe_status
 * @property string|null $stripe_price
 * @property int|null $quantity
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 *
 * @method static Builder<static>|Subscription newModelQuery()
 * @method static Builder<static>|Subscription newQuery()
 * @method static Builder<static>|Subscription query()
 *
 * @mixin Model
 */
final class Subscription extends CashierSubscription implements TenantOwned
{
    use BelongsToOrganization;
}
