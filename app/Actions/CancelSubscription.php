<?php

declare(strict_types=1);

namespace App\Actions;

use App\Billing\BillingFacts;
use App\Exceptions\Billing\NoActiveSubscription;
use App\Models\Organization;
use App\Models\Subscription;

final readonly class CancelSubscription
{
    public function __construct(private BillingFacts $billing) {}

    public function handle(Organization $organization): Subscription
    {
        $subscription = $this->billing->currentSubscription($organization);

        if (! $subscription instanceof Subscription || $subscription->ended()) {
            throw NoActiveSubscription::for($organization);
        }

        $subscription->setRelation('owner', $organization);

        foreach ($subscription->items as $item) {
            $item->setRelation('subscription', $subscription);
        }

        return $subscription->cancel();
    }
}
