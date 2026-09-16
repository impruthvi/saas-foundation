<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the resolved tenant and carries it into background work.
 *
 * Laravel already dehydrates `Illuminate\Log\Context` into every queue payload
 * and hydrates it on `JobProcessing`, before the payload is unserialized. This
 * provider is the half the framework cannot supply: turning that key back into a
 * resolved tenant, and — the part that is easy to miss — dropping the tenant when
 * the payload carries none.
 *
 * `Repository::hydrate()` dispatches `Hydrated` for every job, including jobs
 * with no context at all. A listener that only acts when the key is present
 * leaves the previous job's organization resolved on a long-lived worker, which
 * is precisely the cross-tenant leak M1 exists to prevent (D24).
 */
final class TenancyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Context::hydrated(function (Repository $context): void {
            $tenant = $this->app->make(TenantContext::class);

            $organizationId = $context->get(TenantContext::KEY);

            if (! is_int($organizationId)) {
                $tenant->forget();

                return;
            }

            $tenant->setId($organizationId);
        });
    }
}
