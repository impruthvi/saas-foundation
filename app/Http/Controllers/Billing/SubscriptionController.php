<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Actions\CancelSubscription;
use App\Actions\ResumeSubscription;
use App\Exceptions\Billing\NoActiveSubscription;
use App\Exceptions\Billing\SubscriptionNotCancelled;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SubscriptionActionRequest;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response;

final class SubscriptionController extends Controller
{
    public function destroy(
        SubscriptionActionRequest $request,
        CancelSubscription $cancel,
        TenantContext $tenant,
    ): RedirectResponse {
        $organization = $tenant->current();
        abort_unless($organization instanceof Organization, Response::HTTP_FORBIDDEN);

        try {
            $cancel->handle($organization);
        } catch (NoActiveSubscription $billingRefused) {
            return back()->withErrors(['billing' => $billingRefused->getMessage()]);
        } catch (ApiErrorException $apiErrorException) {
            report($apiErrorException);

            return back()->withErrors([
                'billing' => __('The payment provider is unavailable. Try again.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscription cancellation scheduled.')]);

        return back();
    }

    public function update(
        SubscriptionActionRequest $request,
        ResumeSubscription $resume,
        TenantContext $tenant,
    ): RedirectResponse {
        $organization = $tenant->current();
        abort_unless($organization instanceof Organization, Response::HTTP_FORBIDDEN);

        try {
            $resume->handle($organization);
        } catch (SubscriptionNotCancelled $billingRefused) {
            return back()->withErrors(['billing' => $billingRefused->getMessage()]);
        } catch (ApiErrorException $apiErrorException) {
            report($apiErrorException);

            return back()->withErrors([
                'billing' => __('The payment provider is unavailable. Try again.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscription resumed.')]);

        return back();
    }
}
