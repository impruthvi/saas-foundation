<?php

declare(strict_types=1);

use App\Exceptions\CrossTenantAccess;
use App\Exceptions\TenantContextMissing;
use App\Models\FailedWebhookEvent;
use App\Models\Organization;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Events\WebhookReceived;
use Stripe\WebhookSignature;

beforeEach(function (): void {
    config(['cashier.webhook.secret' => 'whsec_testing']);
});

/**
 * Send a webhook through the signed public endpoint.
 *
 * @param  array<string, mixed>  $payload
 */
function postStripeWebhook(array $payload, bool $validSignature = true): TestResponse
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = $validSignature
        ? WebhookSignature::generateSignatureHeader($json, 'whsec_testing')
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
 * @return array<string, mixed>
 */
function subscriptionWebhookPayload(
    string $eventId = 'evt_subscription_created',
    string $type = 'customer.subscription.created',
    string $customerId = 'cus_acme',
): array {
    return [
        'id' => $eventId,
        'type' => $type,
        'data' => [
            'object' => [
                'id' => 'sub_pro',
                'customer' => $customerId,
                'status' => 'active',
                'metadata' => ['type' => 'default'],
                'items' => [
                    'data' => [[
                        'id' => 'si_pro',
                        'price' => [
                            'id' => 'price_pro',
                            'product' => 'prod_pro',
                        ],
                        'quantity' => 1,
                    ]],
                ],
            ],
        ],
    ];
}

it('creates a subscription inside the organization named by the customer', function (): void {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_acme']);

    postStripeWebhook(subscriptionWebhookPayload())
        ->assertOk()
        ->assertSeeText('Webhook Handled');

    $this->assertDatabaseHas('subscriptions', [
        'organization_id' => $organization->id,
        'stripe_id' => 'sub_pro',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro',
    ]);
    $this->assertDatabaseHas('subscription_items', [
        'stripe_id' => 'si_pro',
        'stripe_product' => 'prod_pro',
        'stripe_price' => 'price_pro',
        'quantity' => 1,
    ]);
    expect(resolve(TenantContext::class)->hasTenant())->toBeFalse();
});

it('resolves customer events from the object id', function (): void {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_acme']);
    $subscription = resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Subscription => Subscription::query()->create([
            'type' => 'default',
            'stripe_id' => 'sub_pro',
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]),
    );

    postStripeWebhook([
        'id' => 'evt_customer_deleted',
        'type' => 'customer.deleted',
        'data' => ['object' => ['id' => 'cus_acme']],
    ])->assertOk();

    expect($organization->fresh()?->stripe_id)->toBeNull();

    resolve(TenantContext::class)->runFor($organization, function () use ($subscription): void {
        expect($subscription->fresh()?->stripe_status)->toBe('canceled')
            ->and($subscription->fresh()?->ends_at?->isSameSecond(now()))->toBeTrue();
    });
});

it('retains an event whose Stripe customer has no organization', function (): void {
    postStripeWebhook(subscriptionWebhookPayload(customerId: 'cus_deleted'))
        ->assertOk()
        ->assertSeeText('Webhook retained.');

    $failedEvent = FailedWebhookEvent::query()->sole();

    expect($failedEvent->stripe_event_id)->toBe('evt_subscription_created')
        ->and($failedEvent->stripe_customer_id)->toBe('cus_deleted')
        ->and($failedEvent->reason)->toBe('OrganizationNotFound')
        ->and($failedEvent->payload)->toBe(subscriptionWebhookPayload(customerId: 'cus_deleted'));
});

it('retains tenant boundary failures and acknowledges them to Stripe', function (Throwable $exception, string $reason): void {
    Organization::factory()->create(['stripe_id' => 'cus_acme']);

    Event::listen(WebhookReceived::class, static function () use ($exception): never {
        throw $exception;
    });

    postStripeWebhook(subscriptionWebhookPayload())
        ->assertOk()
        ->assertSeeText('Webhook retained.');

    expect(FailedWebhookEvent::query()->sole()->reason)->toBe($reason);
})->with([
    'missing tenant' => [TenantContextMissing::forModel(Subscription::class), 'TenantContextMissing'],
    'cross-tenant access' => [CrossTenantAccess::forModel(Subscription::class, 2, 1), 'CrossTenantAccess'],
]);

it('lets Stripe retry failures unrelated to tenancy', function (): void {
    Organization::factory()->create(['stripe_id' => 'cus_acme']);

    Event::listen(WebhookReceived::class, static function (): never {
        throw new RuntimeException('The database is temporarily unavailable.');
    });

    postStripeWebhook(subscriptionWebhookPayload())->assertServerError();

    $this->assertDatabaseEmpty('failed_webhook_events');
});

it('passes events without a customer through to Cashier', function (): void {
    postStripeWebhook([
        'id' => 'evt_ping',
        'type' => 'ping',
        'data' => ['object' => []],
    ])->assertOk();

    $this->assertDatabaseEmpty('failed_webhook_events');
});

it('rejects a webhook with an invalid Stripe signature', function (): void {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_acme']);

    postStripeWebhook(subscriptionWebhookPayload(), validSignature: false)
        ->assertForbidden();

    $this->assertDatabaseMissing('subscriptions', [
        'organization_id' => $organization->id,
        'stripe_id' => 'sub_pro',
    ]);
    $this->assertDatabaseEmpty('failed_webhook_events');
});
