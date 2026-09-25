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
 * Tells the product where the console is, and only an operator.
 *
 * The product never names the console itself. This middleware belongs to the
 * console and joins the `web` group from the console's provider, so once the
 * console is removed the address is never shared and the link cannot outlive
 * what it points at. It shares per request because shared props do not survive
 * between requests everywhere this app runs.
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
