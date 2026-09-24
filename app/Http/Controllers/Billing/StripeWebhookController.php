<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Billing\ApplyStripeEvent;
use App\Enums\UnappliedWebhook;
use Illuminate\Http\Request;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe's events, delivered over HTTP.
 *
 * Placing the event, guarding its order and keeping what cannot be applied
 * belong to ApplyStripeEvent. This controller only hands it Cashier's handlers
 * and turns the result into what Stripe is told: an event kept rather than
 * applied is still acknowledged, so Stripe stops redelivering it. Other
 * failures return an error so Stripe can deliver them again.
 */
final class StripeWebhookController extends WebhookController
{
    public function __construct(private readonly ApplyStripeEvent $applyStripeEvent)
    {
        parent::__construct();
    }

    public function __invoke(Request $request): Response
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), true) ?: [];

        $result = $this->applyStripeEvent->handle(
            $payload,
            fn (): Response => $this->forwardToCashier($request),
        );

        return match ($result) {
            UnappliedWebhook::Unplaceable => new Response('Webhook retained.', Response::HTTP_OK),
            UnappliedWebhook::Superseded => new Response('Webhook superseded.', Response::HTTP_OK),
            default => $result,
        };
    }

    private function forwardToCashier(Request $request): Response
    {
        $response = parent::handleWebhook($request);

        return $response instanceof Response ? $response : new Response;
    }
}
