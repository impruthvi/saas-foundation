<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;

/**
 * Restores the tenant from queue context and clears it when a payload carries
 * none, preventing long-lived workers from retaining the previous job's tenant.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

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
