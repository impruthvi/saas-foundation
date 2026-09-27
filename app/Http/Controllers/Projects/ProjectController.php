<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\CreateProject;
use App\Billing\BillingFacts;
use App\Billing\PlanCatalog;
use App\Entitlements\ResolveAllowance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Resolution\FeatureTypeMismatch;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Resolution\UnknownFeature;
use Impruthvi\CashierEntitlements\Usage\LimitExceeded;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class ProjectController extends Controller
{
    private const int PER_PAGE = 25;

    public function index(
        Request $request,
        TenantContext $tenant,
        OwnerLocator $owners,
        ResolveAllowance $allowances,
        LocalResolver $resolver,
        PlanCatalog $catalog,
        BillingFacts $facts,
        NativeStateStore $states,
    ): Response {
        Gate::authorize('viewAny', Project::class);

        $organization = $tenant->current();
        abort_unless($organization instanceof Organization, HttpResponse::HTTP_FORBIDDEN);

        try {
            $allowance = $this->allowance($organization, $owners, $allowances, $resolver, $catalog);
        } catch (ReadFailure|FeatureTypeMismatch|UnknownFeature $configurationFailure) {
            report($configurationFailure);

            abort(HttpResponse::HTTP_SERVICE_UNAVAILABLE, __('Projects are temporarily unavailable.'));
        }

        return Inertia::render('projects/Index', [
            'projects' => Project::query()
                ->latest('id')
                ->paginate(self::PER_PAGE)
                ->through(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'createdAt' => $project->created_at?->toFormattedDateString(),
                ]),
            'allowance' => $allowance,
            'planChangePending' => $this->planChangePending($owners->reference($organization), $states),
            'canCreate' => $request->user()?->can('create', Project::class) ?? false,
            'accessEndsAt' => $facts->state($organization) === BillingFacts::STATE_GRACE_PERIOD
                ? $facts->currentSubscription($organization)?->ends_at?->toFormattedDateString()
                : null,
        ]);
    }

    public function store(
        StoreProjectRequest $request,
        CreateProject $create,
        TenantContext $tenant,
        OwnerLocator $owners,
        ResolveAllowance $allowances,
        LocalResolver $resolver,
        PlanCatalog $catalog,
    ): RedirectResponse|JsonResponse {
        $organization = $tenant->current();
        abort_unless($organization instanceof Organization, HttpResponse::HTTP_FORBIDDEN);

        try {
            $create->handle(
                $organization,
                $request->projectName(),
                $request->idempotencyToken(),
            );
        } catch (LimitExceeded) {
            $details = $this->allowance($organization, $owners, $allowances, $resolver, $catalog);
            $message = $details['upgradePlan'] === null
                ? __('This organization has used all :usage of its :limit projects.', $details)
                : __('This organization has used all :usage of its :limit projects. Upgrade to :upgradePlan to create another.', $details);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['project' => [$message]],
                    ...$details,
                ], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            return back()
                ->withErrors(['project' => $message])
                ->with('projectLimit', $details);
        } catch (ReadFailure|FeatureTypeMismatch|UnknownFeature $configurationFailure) {
            report($configurationFailure);

            abort(HttpResponse::HTTP_SERVICE_UNAVAILABLE, __('Project creation is temporarily unavailable.'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project created.')]);

        return to_route('projects.index', status: HttpResponse::HTTP_SEE_OTHER);
    }

    /**
     * `remaining` is computed here so the screen has no threshold of its own. Clamped
     * because usage above the limit is real after a downgrade; null means unlimited.
     *
     * @return array{limit: int|null, usage: int, remaining: int|null, upgradePlan: string|null}
     */
    private function allowance(
        Organization $organization,
        OwnerLocator $owners,
        ResolveAllowance $allowances,
        LocalResolver $resolver,
        PlanCatalog $catalog,
    ): array {
        $owner = $owners->reference($organization);
        $at = Date::now()->toDateTimeImmutable();
        $limit = $allowances->limit($owner, 'projects', $at);
        $usage = $resolver->usageStore()->usage($owner, 'projects', $at);

        return [
            'limit' => $limit,
            'usage' => $usage,
            'remaining' => $limit === null ? null : max(0, $limit - $usage),
            'upgradePlan' => $this->cheapestPlanAbove($catalog, $usage),
        ];
    }

    /**
     * A webhook has asked for a refresh that has not landed, typically because no queue
     * worker is running. Display only: the limit is still enforced from what resolved.
     */
    private function planChangePending(OwnerReference $owner, NativeStateStore $states): bool
    {
        $state = $states->state($owner);

        return $state !== null && $state['requested_sequence'] > $state['completed_sequence'];
    }

    private function cheapestPlanAbove(PlanCatalog $catalog, int $usage): ?string
    {
        $candidates = [];

        foreach ($catalog->plans() as $plan) {
            foreach ($plan->prices as $price) {
                if (! array_key_exists('projects', $price->allowances)) {
                    continue;
                }

                $allowance = $price->allowances['projects'];

                if (! is_bool($allowance) && ($allowance === null || $allowance > $usage)) {
                    $candidates[] = ['amount' => $price->amount, 'name' => $plan->name];
                }
            }
        }

        usort($candidates, fn (array $left, array $right): int => $left['amount'] <=> $right['amount']);

        return $candidates[0]['name'] ?? null;
    }
}
