<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

/**
 * Points Cashier at the organization and at this application's tenant-scoped
 * subscription models.
 *
 * Cashier's own webhook route is turned off. It writes subscription rows with no
 * organization resolved, which the tenant scope refuses; this application
 * registers a route that resolves the organization from the Stripe customer
 * first.
 */
final class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Cashier::useCustomerModel(Organization::class);
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);

        Cashier::ignoreRoutes();
    }
}
