<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Billing\ApplyStripeEvent;
use App\Enums\WebhookOutcome;
use Illuminate\Http\Request;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Events kept rather than applied are acknowledged so Stripe stops redelivering; other
 * failures return an error so Stripe retries.
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
            WebhookOutcome::Unplaceable => new Response('Webhook retained.', Response::HTTP_OK),
            WebhookOutcome::Superseded => new Response('Webhook superseded.', Response::HTTP_OK),
            default => $result instanceof Response ? $result : new Response,
        };
    }

    private function forwardToCashier(Request $request): Response
    {
        $response = parent::handleWebhook($request);

        return $response instanceof Response ? $response : new Response;
    }
}
