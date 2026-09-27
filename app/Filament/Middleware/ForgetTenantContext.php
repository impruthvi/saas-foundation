<?php

declare(strict_types=1);

namespace App\Filament\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Livewire updates run the web group, which resolves the operator's own organization.
 * Forgetting it here makes a console read that forgot to name its organization raise
 * instead of showing the operator's rows. Persistent so Livewire replays it.
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
