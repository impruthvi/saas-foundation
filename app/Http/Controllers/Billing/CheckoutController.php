<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Actions\StartBillingCheckout;
use App\Billing\PlanCatalog;
use App\Billing\StripeSecret;
use App\Exceptions\Billing\AlreadySubscribed;
use App\Exceptions\Billing\OrganizationNotBillable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CheckoutRequest;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Inertia\Inertia;
use LogicException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Symfony\Component\HttpFoundation\Response;

final class CheckoutController extends Controller
{
    public function store(
        CheckoutRequest $request,
        StartBillingCheckout $checkout,
        PlanCatalog $catalog,
        TenantContext $tenant,
    ): Response {
        $organization = $tenant->current();
        abort_unless($organization instanceof Organization, Response::HTTP_FORBIDDEN);

        if (! StripeSecret::configured()) {
            return back()->withErrors([
                'billing' => mb_trim(__('Stripe is not configured for this application.').' '.StripeSecret::setupHint()),
            ]);
        }

        try {
            $session = $checkout->handle(
                $organization,
                $request->price($catalog),
                route('organizations.billing.index', ['checkout' => 'success']),
                route('organizations.billing.index', ['checkout' => 'cancelled']),
            );
        } catch (AlreadySubscribed|OrganizationNotBillable $billingRefused) {
            return back()->withErrors(['billing' => $billingRefused->getMessage()]);
        } catch (ApiErrorException $apiErrorException) {
            report($apiErrorException);

            if ($this->isMissingPrice($apiErrorException)) {
                return back()->withErrors([
                    'billing' => mb_trim(__('This Stripe account has no price for this plan yet.').' '.StripeSecret::setupHint()),
                ]);
            }

            return back()->withErrors([
                'billing' => __('The payment provider is unavailable. Try again.'),
            ]);
        }

        $url = $session->asStripeCheckoutSession()->url;
        throw_unless(is_string($url), LogicException::class, 'Stripe returned a checkout session without a URL.');

        return Inertia::location($url);
    }

    private function isMissingPrice(ApiErrorException $exception): bool
    {
        return $exception instanceof InvalidRequestException
            && $exception->getStripeCode() === 'resource_missing'
            && str_contains((string) $exception->getStripeParam(), 'price');
    }
}
