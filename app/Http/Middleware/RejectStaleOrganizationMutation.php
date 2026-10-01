<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The current organization lives in the session, so a page rendered for one
 * organization can submit after another tab switched to a second. Tenant-scoped forms
 * name the organization they were rendered for, and a write naming another is refused
 * here, before route bindings, so a stale page gets a reason rather than a 404.
 */
final readonly class RejectStaleOrganizationMutation
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $named = $request->input('organization');
        $current = $this->tenant->current();

        if ($request->isMethodSafe()
            || ! is_string($named)
            || $named === ''
            || ! $current instanceof Organization
            || $named === $current->slug) {
            return $next($request);
        }

        $reason = __('This organization changed elsewhere. Refresh the page and try again.');

        abort_if($request->header('X-Inertia') === null, Response::HTTP_CONFLICT, $reason);

        Inertia::flash('toast', ['type' => 'error', 'message' => $reason]);

        // 303, not 302: this runs before Inertia's middleware, which would otherwise turn
        // a redirect after PATCH or DELETE into a GET.
        return back(Response::HTTP_SEE_OTHER);
    }
}
