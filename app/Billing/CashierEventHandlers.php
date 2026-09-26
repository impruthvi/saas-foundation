<?php

declare(strict_types=1);

namespace App\Billing;

use Illuminate\Support\Str;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Extends Cashier's controller only to reach its handlers and is never routed. It fires
 * no WebhookHandled, because the entitlement listener re-verifies a signature a replay
 * does not have.
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
