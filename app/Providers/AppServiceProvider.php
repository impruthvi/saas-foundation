<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\PlanCatalog;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapping;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            PriceCatalog::class,
            fn (): PriceCatalog => $this->entitlementCatalog(
                $this->app->make(PlanCatalog::class),
            ),
        );
    }

    public function boot(): void
    {
        Relation::morphMap(['organization' => Organization::class]);

        $this->configureDefaults();
    }

    private function entitlementCatalog(PlanCatalog $catalog): PriceCatalog
    {
        $prices = [];
        $normalizedCatalog = [];

        foreach ($catalog->plans() as $plan) {
            foreach ($plan->prices as $price) {
                $allowances = $price->allowances;
                ksort($allowances);

                $prices[$price->id] = new PriceMapping($plan->key, $allowances);
                $normalizedCatalog[$price->id] = [
                    'plan' => $plan->key,
                    'allowances' => $allowances,
                ];
            }
        }

        ksort($prices);
        ksort($normalizedCatalog);

        return new PriceCatalog(
            version: 'v1-'.mb_substr(hash('sha256', serialize($normalizedCatalog)), 0, 12),
            prices: $prices,
            providerContext: Config::string('cashier-entitlements.provider_context'),
            liveMode: Config::boolean('cashier-entitlements.live_mode'),
        );
    }

    private function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
