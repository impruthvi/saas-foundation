<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\Support\StripeWebhook;

// The entitlement refresh listens for the handled event, so moving the event-applying
// code must leave every row here unchanged.

beforeEach(function (): void {
    config(['cashier.webhook.secret' => StripeWebhook::SECRET]);

    Event::fake([WebhookReceived::class, WebhookHandled::class]);
});

it('announces a subscription event it applies as received and handled', function (): void {
    Organization::factory()->create(['stripe_id' => 'cus_acme']);

    StripeWebhook::post(StripeWebhook::subscriptionPayload())
        ->assertOk()
        ->assertContent('Webhook Handled');

    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->payload['id'] === 'evt_subscription_created');
    Event::assertDispatched(WebhookHandled::class, fn (WebhookHandled $event): bool => $event->payload['id'] === 'evt_subscription_created');
});

it('acknowledges an event type Cashier has no handler for with an empty 200 and no handled event', function (): void {
    Organization::factory()->create(['stripe_id' => 'cus_acme']);

    StripeWebhook::post([
        'id' => 'evt_invoice_created',
        'type' => 'invoice.created',
        'data' => ['object' => ['id' => 'in_1', 'customer' => 'cus_acme']],
    ])
        ->assertOk()
        ->assertContent('');

    Event::assertDispatched(WebhookReceived::class);
    Event::assertNotDispatched(WebhookHandled::class);
});

it('forwards an event without a customer to Cashier without an organization', function (): void {
    StripeWebhook::post([
        'id' => 'evt_ping',
        'type' => 'ping',
        'data' => ['object' => []],
    ])
        ->assertOk()
        ->assertContent('');

    Event::assertDispatched(WebhookReceived::class);
    Event::assertNotDispatched(WebhookHandled::class);
});

it('keeps an event it cannot place away from Cashier entirely', function (): void {
    StripeWebhook::post(StripeWebhook::subscriptionPayload(customerId: 'cus_unknown'))
        ->assertOk()
        ->assertContent('Webhook retained.');

    Event::assertNotDispatched(WebhookReceived::class);
    Event::assertNotDispatched(WebhookHandled::class);
});

it('keeps a superseded event away from Cashier entirely', function (): void {
    Organization::factory()->create(['stripe_id' => 'cus_acme']);
    StripeWebhook::post(StripeWebhook::subscriptionPayload(created: 2_000))->assertOk();
    Event::fake([WebhookReceived::class, WebhookHandled::class]);

    StripeWebhook::post(StripeWebhook::subscriptionPayload(
        eventId: 'evt_subscription_updated',
        type: 'customer.subscription.updated',
        created: 1_000,
    ))
        ->assertOk()
        ->assertContent('Webhook superseded.');

    Event::assertNotDispatched(WebhookReceived::class);
    Event::assertNotDispatched(WebhookHandled::class);
});

it('returns 403 and announces nothing for a delivery with an invalid signature', function (): void {
    Organization::factory()->create(['stripe_id' => 'cus_acme']);

    StripeWebhook::post(StripeWebhook::subscriptionPayload(), validSignature: false)
        ->assertForbidden();

    Event::assertNotDispatched(WebhookReceived::class);
    Event::assertNotDispatched(WebhookHandled::class);
});
