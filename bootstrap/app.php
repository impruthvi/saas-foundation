<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureImpersonationIsLive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdentifyAuditActor;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // SubstituteBindings moves after the tenant is resolved: binding a tenant-owned
        // model queries it, and its scope raises with no organization, turning a
        // cross-tenant 404 into a 500.
        $middleware->web(
            append: [
                HandleAppearance::class,
                // Before bindings so bound models are scoped, and before Inertia so
                // shared props see the resolved tenant.
                // Before the tenant, because ending an impersonation changes who is
                // signed in.
                EnsureImpersonationIsLive::class,
                ResolveTenantContext::class,
                IdentifyAuditActor::class,
                SubstituteBindings::class,
                HandleInertiaRequests::class,
                AddLinkHeadersForPreloadedAssets::class,
            ],
            remove: [SubstituteBindings::class],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
