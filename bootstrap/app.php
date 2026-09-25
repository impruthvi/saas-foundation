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

        // SubstituteBindings is pulled out of its default position and put back
        // after the tenant is resolved. Route model binding queries the model,
        // and a tenant-owned model's global scope raises when no organization is
        // resolved, so binding `{project}` or `{invitation}` in its stock
        // position is a 500 rather than the 404 a cross-tenant request deserves.
        $middleware->web(
            append: [
                HandleAppearance::class,
                // Before bindings, so a bound tenant-owned model is scoped, and
                // before Inertia, so shared props are built with the tenant
                // already resolved rather than resolving one of their own.
                // Before the tenant, because ending an impersonation changes who
                // is signed in and so which organization applies.
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
