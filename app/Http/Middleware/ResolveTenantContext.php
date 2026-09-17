<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves which organization the request is acting for.
 *
 * The current organization is held in the session and changed through an
 * explicit switch, rather than carried in a URL prefix (D26). Resolution is
 * membership-driven: the session only ever names an organization, and this
 * decides whether the user may still act for it.
 *
 *   session names one? ──yes──► still an active member of it? ──yes──► resolve
 *          │ no                          │ no
 *          └──────────────┬──────────────┘
 *                         ▼
 *              their first usable organization (personal first)
 *                         │ none
 *                         ▼
 *                    resolve nothing
 *
 * Resolving nothing is deliberate rather than a failure: an unauthenticated
 * request has no tenant, and a tenant-owned query made without one raises at
 * the model boundary, where the scoping rule lives (D3).
 */
final readonly class ResolveTenantContext
{
    /**
     * The session key naming the current organization.
     */
    public const string SESSION_KEY = 'current_organization_id';

    public function __construct(
        private TenantContext $tenant,
        private MembershipRepository $memberships,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $organization = $user instanceof User
            ? $this->resolve($request, $user)
            : null;

        if (! $organization instanceof Organization) {
            $this->tenant->forget();

            return $next($request);
        }

        $this->tenant->set($organization);

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $organization->id);
        }

        return $next($request);
    }

    private function resolve(Request $request, User $user): ?Organization
    {
        $available = $this->memberships->organizationsFor($user)
            ->filter(fn (Organization $organization): bool => $organization->status->isUsable());

        $chosen = $request->hasSession()
            ? $request->session()->get(self::SESSION_KEY)
            : null;

        if (is_int($chosen)) {
            $current = $available->firstWhere('id', $chosen);

            if ($current instanceof Organization) {
                return $current;
            }
        }

        return $available->first();
    }
}
