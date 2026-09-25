<?php

declare(strict_types=1);

namespace App\Billing;

use Illuminate\Support\Str;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cashier's per-event handlers, run for a recorded event, announcing nothing.
 *
 * Cashier keeps its handlers on its webhook controller, so this extends it only
 * to reach them. It is never routed. A live delivery dispatches WebhookReceived
 * and WebhookHandled around the handler; the entitlement package listens for the
 * second and re-verifies the Stripe signature against the current request, which
 * a replay does not have. The replay asks for the refresh itself instead.
 */
final class CashierEventHandlers extends WebhookController
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function apply(array $payload): Response
    {
        $method = 'handle'.Str::studly(str_replace('.', '_', is_string($payload['type'] ?? null) ? $payload['type'] : ''));

        if (! method_exists($this, $method)) {
            return $this->missingMethod($payload);
        }

        $this->setMaxNetworkRetries();

        $response = $this->{$method}($payload);

        return $response instanceof Response ? $response : new Response;
    }
}
