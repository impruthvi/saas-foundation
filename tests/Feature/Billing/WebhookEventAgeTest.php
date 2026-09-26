<?php

declare(strict_types=1);

use App\Enums\WebhookOutcome;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Tenancy\TenantContext;
use Tests\Support\StripeWebhook;

beforeEach(function (): void {
    config(['cashier.webhook.secret' => StripeWebhook::SECRET]);

    $this->organization = Organization::factory()->create(['stripe_id' => 'cus_acme']);
});

function subscriptionStatus(Organization $organization, string $stripeId = 'sub_pro'): ?string
{
    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): ?string => Subscription::query()->where('stripe_id', $stripeId)->first()?->stripe_status,
    );
}

it('ignores a subscription event older than the one already applied', function (): void {
    StripeWebhook::post(StripeWebhook::subscriptionPayload(created: 2_000))->assertOk();

    StripeWebhook::post(StripeWebhook::subscriptionPayload(
        eventId: 'evt_subscription_updated',
        type: 'customer.subscription.updated',
        created: 1_000,
        status: 'canceled',
    ))
        ->assertOk()
        ->assertSeeText('Webhook superseded.');

    expect(subscriptionStatus($this->organization))->toBe('active')
        ->and(WebhookEvent::query()->where('stripe_event_id', 'evt_subscription_updated')->sole()->outcome_reason)->toBe('SupersededDelivery');

    $this->assertDatabaseHas('subscription_event_watermarks', [
        'organization_id' => $this->organization->id,
        'stripe_id' => 'sub_pro',
        'event_created_at' => 2_000,
    ]);
});

it('applies a subscription event newer than the one already applied', function (): void {
    StripeWebhook::post(StripeWebhook::subscriptionPayload(created: 1_000))->assertOk();

    StripeWebhook::post(StripeWebhook::subscriptionPayload(
        eventId: 'evt_subscription_updated',
        type: 'customer.subscription.updated',
        created: 2_000,
        status: 'past_due',
    ))->assertOk();

    expect(subscriptionStatus($this->organization))->toBe('past_due');

    $this->assertDatabaseHas('subscription_event_watermarks', [
        'organization_id' => $this->organization->id,
        'stripe_id' => 'sub_pro',
        'event_created_at' => 2_000,
    ]);
    expect(WebhookEvent::query()->where('outcome', '!=', WebhookOutcome::Applied)->exists())->toBeFalse();
});

it('applies an event carrying the same timestamp, so a redelivery still lands', function (): void {
    StripeWebhook::post(StripeWebhook::subscriptionPayload(created: 1_000))->assertOk();

    StripeWebhook::post(StripeWebhook::subscriptionPayload(
        eventId: 'evt_subscription_updated',
        type: 'customer.subscription.updated',
        created: 1_000,
        status: 'past_due',
    ))->assertOk();

    expect(subscriptionStatus($this->organization))->toBe('past_due')
        ->and(WebhookEvent::query()->where('outcome', '!=', WebhookOutcome::Applied)->exists())->toBeFalse();
});

it('applies an event with no timestamp rather than dropping it', function (): void {
    StripeWebhook::post(StripeWebhook::subscriptionPayload())->assertOk();

    StripeWebhook::post(StripeWebhook::subscriptionPayload(
        eventId: 'evt_subscription_updated',
        type: 'customer.subscription.updated',
        status: 'past_due',
    ))->assertOk();

    expect(subscriptionStatus($this->organization))->toBe('past_due');
    $this->assertDatabaseMissing('subscription_event_watermarks', [
        'organization_id' => $this->organization->id,
        'stripe_id' => 'sub_pro',
    ]);
    expect(WebhookEvent::query()->where('outcome', '!=', WebhookOutcome::Applied)->exists())->toBeFalse();
});

it('refuses to resurrect a subscription whose deletion arrived before its creation', function (): void {
    StripeWebhook::post(StripeWebhook::subscriptionPayload(
        eventId: 'evt_subscription_deleted',
        type: 'customer.subscription.deleted',
        created: 2_000,
        status: 'canceled',
    ))->assertOk();

    StripeWebhook::post(StripeWebhook::subscriptionPayload(created: 1_000))
        ->assertOk()
        ->assertSeeText('Webhook superseded.');

    $this->assertDatabaseMissing('subscriptions', [
        'organization_id' => $this->organization->id,
        'stripe_id' => 'sub_pro',
    ]);
    $this->assertDatabaseHas('subscription_event_watermarks', [
        'organization_id' => $this->organization->id,
        'stripe_id' => 'sub_pro',
        'event_created_at' => 2_000,
    ]);
});

it('tracks each subscription separately', function (): void {
    StripeWebhook::post(StripeWebhook::subscriptionPayload(created: 2_000))->assertOk();

    StripeWebhook::post(StripeWebhook::subscriptionPayload(
        eventId: 'evt_other_created',
        created: 1_000,
        subscriptionId: 'sub_other',
        itemId: 'si_other',
    ))->assertOk();

    expect(subscriptionStatus($this->organization, 'sub_other'))->toBe('active');

    $this->assertDatabaseHas('subscription_event_watermarks', [
        'organization_id' => $this->organization->id,
        'stripe_id' => 'sub_other',
        'event_created_at' => 1_000,
    ]);
});

it('leaves events that do not write a subscription row unguarded', function (): void {
    StripeWebhook::post(StripeWebhook::subscriptionPayload(created: 2_000))->assertOk();

    StripeWebhook::post([
        'id' => 'evt_customer_deleted',
        'type' => 'customer.deleted',
        'created' => 1_000,
        'data' => ['object' => ['id' => 'cus_acme']],
    ])->assertOk();

    expect($this->organization->fresh()?->stripe_id)->toBeNull()
        ->and(WebhookEvent::query()->where('outcome', '!=', WebhookOutcome::Applied)->exists())->toBeFalse();
    $this->assertDatabaseHas('subscription_event_watermarks', [
        'organization_id' => $this->organization->id,
        'stripe_id' => 'sub_pro',
        'event_created_at' => 2_000,
    ]);
});
