<?php

declare(strict_types=1);

namespace App\Filament\Middleware;

use App\Contracts\Operators;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registered by the console's provider, so the link disappears with the console.
 */
final readonly class ShareAdminConsoleLink
{
    public function __construct(private Operators $operators) {}

    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('adminConsoleUrl', fn (): ?string => ($user = $request->user()) instanceof User && $this->operators->isOperator($user)
            ? Filament::getPanel('admin')->getUrl()
            : null);

        return $next($request);
    }
}
