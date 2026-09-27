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
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MembershipRepository $memberships,
    ) {}

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // withoutRelations(): a policy may have loaded roles onto this instance,
            // which would otherwise be serialized into every page.
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
