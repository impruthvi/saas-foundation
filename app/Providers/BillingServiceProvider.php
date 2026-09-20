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
 * Owns the application's billing catalog and points Cashier at the organization
 * and at this application's tenant-scoped subscription models.
 *
 * Cashier's own webhook route is turned off. It writes subscription rows with no
 * organization resolved, which the tenant scope refuses; this application
 * registers a route that resolves the organization from the Stripe customer
 * first.
 */
final class BillingServiceProvider extends ServiceProvider
{
    /**
     * Cashier decides whether to register its routes while booting, and package
     * providers boot before application ones, so declining has to happen here.
     * Doing it in boot() leaves Cashier's payment page routed and lets this
     * application's webhook win only by shadowing the same URI.
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
        // Fail during boot rather than when the first customer opens billing.
        $this->app->make(PlanCatalog::class);

        Cashier::useCustomerModel(Organization::class);
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);

        $this->loadRoutesFrom(base_path('routes/billing.php'));
    }
}
