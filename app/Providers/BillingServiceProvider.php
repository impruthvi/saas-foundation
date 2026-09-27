<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\PlanCatalog;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

/**
 * Cashier's webhook route is off: it writes subscription rows before any organization
 * is resolved, which the tenant scope refuses.
 */
final class BillingServiceProvider extends ServiceProvider
{
    /**
     * Must run in register(): Cashier decides on routes while booting, and package
     * providers boot first.
     */
    public function register(): void
    {
        Cashier::ignoreRoutes();

        $this->app->singleton(
            PlanCatalog::class,
            fn (): PlanCatalog => PlanCatalog::fromConfig(config('billing')),
        );
    }

    public function boot(): void
    {
        // Fail at boot rather than when the first customer opens billing.
        $this->app->make(PlanCatalog::class);

        Cashier::useCustomerModel(Organization::class);
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);

        $this->loadRoutesFrom(base_path('routes/billing.php'));
    }
}
