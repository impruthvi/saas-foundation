<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Billing\BillingFacts;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\Price;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class BillingController extends Controller
{
    public function index(
        Request $request,
        TenantContext $tenant,
        PlanCatalog $catalog,
        BillingFacts $facts,
    ): Response {
        Gate::authorize('viewAny', Subscription::class);

        $organization = $tenant->current();
        abort_unless($organization instanceof Organization, 403);

        $state = $facts->state($organization);
        $subscription = $facts->currentSubscription($organization);
        $price = $facts->currentPrice($organization);
        $plan = $facts->currentPlan($organization);

        return Inertia::render('billing/Index', [
            'plans' => array_map($this->presentPlan(...), $catalog->plans()),
            'subscription' => $state === BillingFacts::STATE_NONE ? null : [
                'state' => $state,
                'plan' => $plan instanceof Plan ? [
                    'key' => $plan->key,
                    'name' => $plan->name,
                ] : null,
                'price' => $price instanceof Price ? $this->presentPrice($price) : null,
                'trialEndsAt' => $subscription?->trial_ends_at?->toFormattedDateString(),
                'endsAt' => $subscription?->ends_at?->toFormattedDateString(),
            ],
            'canManageBilling' => $request->user()?->can('manage', Subscription::class) ?? false,
        ]);
    }

    /** @return array{key: string, name: string, prices: list<array{id: string, interval: string, currency: string, amount: int}>} */
    private function presentPlan(Plan $plan): array
    {
        return [
            'key' => $plan->key,
            'name' => $plan->name,
            'prices' => array_map($this->presentPrice(...), $plan->prices),
        ];
    }

    /** @return array{id: string, interval: string, currency: string, amount: int} */
    private function presentPrice(Price $price): array
    {
        return [
            'id' => $price->id,
            'interval' => $price->interval,
            'currency' => $price->currency,
            'amount' => $price->amount,
        ];
    }
}
