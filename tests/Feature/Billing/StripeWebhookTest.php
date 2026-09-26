<?php

declare(strict_types=1);

use App\Enums\WebhookOutcome;
use App\Exceptions\CrossTenantAccess;
use App\Exceptions\TenantContextMissing;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\Support\StripeWebhook;

beforeEach(function (): void {
    config(['cashier.webhook.secret' => StripeWebhook::SECRET]);
});

/**
 * Send a webhook through the signed public endpoint.
 *
 * @param  array<string, mixed>  $payload
 */
function postStripeWebhook(array $payload, bool $validSignature = true): TestResponse
{
    return StripeWebhook::post($payload, $validSignature);
}

/**
 * @return array<string, mixed>
 */
function subscriptionWebhookPayload(
    string $eventId = 'evt_subscription_created',
    string $type = 'customer.subscription.created',
    string $customerId = 'cus_acme',
): array {
    return StripeWebhook::subscriptionPayload($eventId, $type, $customerId);
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

    $failedEvent = WebhookEvent::query()->sole();

    expect($failedEvent->stripe_event_id)->toBe('evt_subscription_created')
        ->and($failedEvent->stripe_customer_id)->toBe('cus_deleted')
        ->and($failedEvent->outcome)->toBe(WebhookOutcome::Unplaceable)
        ->and($failedEvent->outcome_reason)->toBe('OrganizationNotFound')
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

    expect(WebhookEvent::query()->sole())
        ->outcome->toBe(WebhookOutcome::Unplaceable)
        ->outcome_reason->toBe($reason);
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

    expect(WebhookEvent::query()->sole())
        ->outcome->toBe(WebhookOutcome::Errored)
        ->outcome_reason->toBe('RuntimeException');
});

it('passes events without a customer through to Cashier', function (): void {
    postStripeWebhook([
        'id' => 'evt_ping',
        'type' => 'ping',
        'data' => ['object' => []],
    ])->assertOk();

    expect(WebhookEvent::query()->sole()->outcome)->toBe(WebhookOutcome::Applied);
});

it('rejects a webhook with an invalid Stripe signature', function (): void {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_acme']);

    postStripeWebhook(subscriptionWebhookPayload(), validSignature: false)
        ->assertForbidden();

    $this->assertDatabaseMissing('subscriptions', [
        'organization_id' => $organization->id,
        'stripe_id' => 'sub_pro',
    ]);
    $this->assertDatabaseEmpty('webhook_events');
});
