<?php

declare(strict_types=1);

namespace App\Filament\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console reads across organizations, so it holds none of its own.
 *
 * A page load runs the panel's middleware, which resolves no organization. A
 * Livewire update runs the `web` group instead, and that resolves the
 * operator's own organization from their session. A console read that forgot
 * to name its organization would then quietly show the operator's own rows as
 * the customer's. Forgetting here, on both paths, makes that mistake raise
 * instead. This is registered as persistent so Livewire replays it on updates.
 */
final readonly class ForgetTenantContext
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenant->forget();

        return $next($request);
    }
}
