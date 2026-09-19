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
 * Resolves the session's organization when membership still allows it, or
 * falls back to the user's first active organization.
 */
final readonly class ResolveTenantContext
{
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
