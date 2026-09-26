<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Contracts\Operators;
use App\Filament\Middleware\ForgetTenantContext;
use App\Filament\Middleware\ShareAdminConsoleLink;
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
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * No login page of its own: unauthenticated visits fall through to Fortify, so
 * passkeys, two-factor and throttling apply. Authentication is persistent so a revoked
 * operator's open page stops working.
 */
final class AdminConsoleServiceProvider extends PanelProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->singleton(Operators::class, OperatorTable::class);
    }

    /**
     * Appended to the web group rather than placed in bootstrap/app.php's order: it
     * only shares a prop and must leave with the console.
     */
    public function boot(): void
    {
        $this->app->make(Router::class)->pushMiddlewareToGroup('web', ShareAdminConsoleLink::class);
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
