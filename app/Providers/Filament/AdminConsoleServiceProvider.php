<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Contracts\Operators;
use App\Filament\Middleware\ForgetTenantContext;
use App\Filament\Support\OperatorTable;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The admin console: screens over application services, and nothing else.
 *
 * It has no login page of its own. An unauthenticated visit falls through to
 * the product's Fortify login, so passkeys, two-factor and throttling apply to
 * operators unchanged.
 *
 *   page load        ─▶ panel middleware (no `web` group) ─▶ no organization
 *   Livewire update  ─▶ `web` group ─▶ operator's own organization resolved
 *                                    ─▶ ForgetTenantContext (persistent) ─▶ none
 *
 * Authentication is persistent too, so revoking an operator stops the actions
 * on a page they already have open, not only the next page they load.
 */
final class AdminConsoleServiceProvider extends PanelProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->singleton(Operators::class, OperatorTable::class);
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(config('app.name').' console')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->middleware([
                ForgetTenantContext::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ], isPersistent: true);
    }
}
