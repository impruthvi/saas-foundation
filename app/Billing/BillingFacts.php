<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Organization;
use App\Models\Subscription;
use LogicException;

/**
 * Loads relations before Cashier dereferences them, because lazy loading is prevented.
 */
final readonly class BillingFacts
{
    public const string STATE_ACTIVE = 'active';

    public const string STATE_GRACE_PERIOD = 'grace_period';

    /** Open but not paid for, such as unpaid or incomplete: it blocks checkout until cancelled. */
    public const string STATE_INACTIVE = 'inactive';

    public const string STATE_NONE = 'none';

    public const string STATE_PAST_DUE = 'past_due';

    public function __construct(private PlanCatalog $catalog) {}

    public function currentSubscription(Organization $organization): ?Subscription
    {
        $organization->loadMissing('subscriptions.items');

        $subscription = $organization->subscription();

        throw_if($subscription instanceof \Laravel\Cashier\Subscription && ! $subscription instanceof Subscription, LogicException::class, 'Cashier returned an unexpected subscription model.');

        return $subscription;
    }

    public function hasOpenSubscription(Organization $organization): bool
    {
        return $this->openSubscription($organization) instanceof Subscription;
    }

    public function openSubscription(Organization $organization): ?Subscription
    {
        $subscription = $this->currentSubscription($organization);

        return $subscription instanceof Subscription && ! $subscription->ended()
            ? $subscription
            : null;
    }

    /** @return self::STATE_* */
    public function state(Organization $organization): string
    {
        $subscription = $this->currentSubscription($organization);

        if (! $subscription instanceof Subscription || $subscription->ended()) {
            return self::STATE_NONE;
        }

        if ($subscription->onGracePeriod()) {
            return self::STATE_GRACE_PERIOD;
        }

        if ($subscription->pastDue()) {
            return self::STATE_PAST_DUE;
        }

        return $subscription->active() ? self::STATE_ACTIVE : self::STATE_INACTIVE;
    }

    public function currentPrice(Organization $organization): ?Price
    {
        $subscription = $this->currentSubscription($organization);

        if (! $subscription instanceof Subscription || $subscription->ended()) {
            return null;
        }

        foreach ($this->priceIds($subscription) as $priceId) {
            $price = $this->catalog->findPrice($priceId);

            if ($price instanceof Price) {
                return $price;
            }
        }

        return null;
    }

    public function currentPlan(Organization $organization): ?Plan
    {
        $price = $this->currentPrice($organization);

        return ! $price instanceof Price ? null : $this->catalog->findPlan($price->planKey);
    }

    /** @return list<string> */
    private function priceIds(Subscription $subscription): array
    {
        $priceIds = $subscription->items
            ->pluck('stripe_price')
            ->filter(fn (mixed $priceId): bool => is_string($priceId))
            ->values()
            ->all();

        if ($subscription->stripe_price !== null) {
            array_unshift($priceIds, $subscription->stripe_price);
        }

        return array_values(array_unique($priceIds));
    }
}
