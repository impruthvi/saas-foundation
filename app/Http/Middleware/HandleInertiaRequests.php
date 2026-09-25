<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Impersonation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MembershipRepository $memberships,
    ) {}

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // withoutRelations(), because a policy that ran earlier in the
            // request loaded roles and permissions onto this very instance, and
            // sharing the model whole serializes them - pivot rows, team key
            // and all - into every page payload.
            'auth' => [
                'user' => $request->user()?->withoutRelations(),
            ],
            'organization' => fn (): ?array => $this->present($this->tenant->current()),
            'organizations' => fn (): array => $this->availableOrganizations($request),
            'impersonation' => fn (): ?array => $this->impersonation($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Every organization the current user may act for.
     *
     * @return array<int, array{id: int, name: string, slug: string, personal: bool}>
     */
    private function availableOrganizations(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return [];
        }

        return $this->memberships->organizationsFor($user)
            ->filter(fn (Organization $organization): bool => $organization->status->isUsable())
            ->map(fn (Organization $organization): array => $this->present($organization))
            ->values()
            ->all();
    }

    /**
     * @return ($organization is null ? null : array{id: int, name: string, slug: string, personal: bool})
     */
    private function present(?Organization $organization): ?array
    {
        if (! $organization instanceof Organization) {
            return null;
        }

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'personal' => $organization->personal,
        ];
    }

    /**
     * The impersonation this session is in, for the banner that ends it.
     *
     * @return array{user: string|null, operator: string|null, expiresAt: string}|null
     */
    private function impersonation(Request $request): ?array
    {
        $impersonation = Impersonation::liveIn($request->session());

        if (! $impersonation instanceof Impersonation) {
            return null;
        }

        return [
            'user' => $impersonation->user?->name,
            'operator' => $impersonation->operator?->name,
            'expiresAt' => $impersonation->expires_at->toIso8601String(),
        ];
    }
}
