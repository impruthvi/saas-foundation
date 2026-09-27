<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Billing\PlanCatalog;
use Illuminate\Testing\TestResponse;

final class StripeWebhook
{
    public const string SECRET = 'whsec_testing';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function post(array $payload, bool $validSignature = true): TestResponse
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = $validSignature
            ? 't='.$timestamp.',v1='.hash_hmac('sha256', "{$timestamp}.{$json}", self::SECRET)
            : 't=1,v1=invalid';

        return test()->call(
            'POST',
            route('cashier.webhook'),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signature,
            ],
            content: $json,
        );
    }

    /**
     * Stripe reports an active subscription on the plan's first price, then says so by
     * a signed webhook. Posted across every owner because the refresh it triggers reads
     * the entitlement ledgers by owner.
     */
    public static function reportSubscription(
        FakeStripeApi $provider,
        string $customerId,
        string $subscriptionId,
        string $itemId,
        int $created = 1_000,
        string $plan = 'pro',
    ): void {
        $provider->withActiveSubscription(
            $customerId,
            (string) resolve(PlanCatalog::class)->findPlan($plan)?->prices[0]->id,
            $subscriptionId,
        );

        TenantQueryGuard::allowUnscoped(fn () => self::post(self::subscriptionPayload(
            customerId: $customerId,
            created: $created,
            subscriptionId: $subscriptionId,
            itemId: $itemId,
        ))->assertOk());
    }

    /**
     * created is omitted entirely when null so the no-timestamp path can be exercised.
     *
     * @return array<string, mixed>
     */
    public static function subscriptionPayload(
        string $eventId = 'evt_subscription_created',
        string $type = 'customer.subscription.created',
        string $customerId = 'cus_acme',
        ?int $created = null,
        string $status = 'active',
        string $subscriptionId = 'sub_pro',
        string $itemId = 'si_pro',
    ): array {
        return array_filter([
            'id' => $eventId,
            'type' => $type,
            'livemode' => false,
            'created' => $created,
            'data' => [
                'object' => [
                    'id' => $subscriptionId,
                    'customer' => $customerId,
                    'status' => $status,
                    'metadata' => ['type' => 'default'],
                    'items' => [
                        'data' => [[
                            'id' => $itemId,
                            'price' => [
                                'id' => 'price_pro',
                                'product' => 'prod_pro',
                            ],
                            'quantity' => 1,
                        ]],
                    ],
                ],
            ],
        ], static fn (mixed $value): bool => $value !== null);
    }
}
