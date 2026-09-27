<?php

declare(strict_types=1);

namespace App\Providers;

use App\Audit\AuditActor;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\StripeWebhookForwarding;
use App\Console\Commands\ReconcileEntitlementsCommand;
use App\Contracts\Operators;
use App\Entitlements\ResolveAllowance;
use App\Enums\AuditSource;
use App\Impersonation\NoOperators;
use App\Models\Organization;
use App\Tenancy\ResolveTenantForRefresh;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapping;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use LogicException;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The admin console binds the real list. Without it nobody is an operator.
        $this->app->singletonIf(Operators::class, NoOperators::class);

        $this->app->singleton(
            PriceCatalog::class,
            fn (): PriceCatalog => $this->entitlementCatalog(
                $this->app->make(PlanCatalog::class),
            ),
        );

        $this->app->bind(
            ResolveAllowance::class,
            fn (): ResolveAllowance => new ResolveAllowance(
                $this->app->make(LocalResolver::class),
                $this->freeAllowances($this->app->make(PlanCatalog::class)),
            ),
        );
    }

    public function boot(): void
    {
        Relation::morphMap(['organization' => Organization::class]);

        // Entitlement refreshes read a tenant-scoped relation on the queue and the
        // console, so the organization is resolved from the owner reference they carry.
        Bus::pipeThrough([ResolveTenantForRefresh::class]);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileEntitlementsCommand::class]);
            StripeWebhookForwarding::registerDevCommand();
        }

        // Console acts have no person behind them; a queued job replaces this with its
        // payload's actor.
        Event::listen(CommandStarting::class, static function (): void {
            AuditActor::source(AuditSource::Console)->bind();
        });

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

    /** @return array<string, bool|int|null> */
    private function freeAllowances(PlanCatalog $catalog): array
    {
        $plan = $catalog->findPlan('free');

        throw_unless($plan instanceof Plan, LogicException::class, 'Billing must declare the Free plan used as the entitlement floor.');

        $allowances = null;

        foreach ($plan->prices as $price) {
            $priceAllowances = $price->allowances;
            ksort($priceAllowances);

            throw_if($allowances !== null && $allowances !== $priceAllowances, LogicException::class, 'Every Free-plan price must declare the same entitlement floor.');

            $allowances = $priceAllowances;
        }

        return $allowances ?? throw new LogicException('The Free plan must declare an entitlement floor.');
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
