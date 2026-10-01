<?php

declare(strict_types=1);

use App\Enums\WebhookOutcome;
use App\Models\Organization;
use App\Models\WebhookEvent;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\Support\StripeWebhook;

beforeEach(function (): void {
    config(['cashier.webhook.secret' => StripeWebhook::SECRET]);
});

it('records an applied subscription event with what it was about', function (): void {
    Organization::factory()->create(['stripe_id' => 'cus_acme']);
    $this->travelTo('2026-09-25 10:00:00');
    $payload = StripeWebhook::subscriptionPayload(created: 1_000);
    $payload['data']['object']['metadata']['email'] = 'owner@example.com';

    StripeWebhook::post($payload)->assertOk();

    expect(WebhookEvent::query()->sole())
        ->stripe_event_id->toBe('evt_subscription_created')
        ->type->toBe('customer.subscription.created')
        ->stripe_customer_id->toBe('cus_acme')
        ->stripe_object_id->toBe('sub_pro')
        ->stripe_created_at->toBe(1_000)
        ->outcome->toBe(WebhookOutcome::Applied)
        ->applied_at->toDateTimeString()->toBe('2026-09-25 10:00:00')
        ->deliveries->toBe(1)
        ->payload->toBe([]);
});

it('retains a replayable payload and redacts it after a later successful delivery', function (): void {
    $payload = StripeWebhook::subscriptionPayload(customerId: 'cus_late');
    $payload['data']['object']['metadata']['email'] = 'owner@example.com';

    StripeWebhook::post($payload)->assertOk();

    expect(WebhookEvent::query()->sole()->payload)->toBe($payload);

    Organization::factory()->create(['stripe_id' => 'cus_late']);
    StripeWebhook::post($payload)->assertOk();

    expect(WebhookEvent::query()->sole()->payload)->toBeEmpty();
});

it('does not restore a payload or replay access after an applied event becomes unplaceable', function (): void {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_acme']);
    $payload = StripeWebhook::subscriptionPayload();
    $payload['data']['object']['metadata']['email'] = 'owner@example.com';
    StripeWebhook::post($payload)->assertOk();
    $organization->forceFill(['stripe_id' => null])->save();

    StripeWebhook::post($payload)->assertOk();

    $event = WebhookEvent::query()->sole();
    expect($event->outcome)->toBe(WebhookOutcome::Unplaceable)
        ->and($event->applied_at)->not->toBeNull()
        ->and($event->payload)->toBeEmpty()
        ->and($event->canBeReplayed())->toBeFalse();
});

it('redacts retained terminal payloads when the data migration runs', function (): void {
    $terminal = WebhookEvent::factory()->create(['payload' => ['email' => 'owner@example.com']]);
    $replayable = WebhookEvent::factory()->unplaceable()->create(['payload' => ['email' => 'pending@example.com']]);
    $previouslyApplied = WebhookEvent::factory()->create([
        'outcome' => WebhookOutcome::Errored,
        'payload' => ['email' => 'old@example.com'],
    ]);
    $migration = require base_path('database/migrations/2026_10_01_014444_redact_terminal_webhook_payloads.php');

    $migration->up();

    expect($terminal->fresh()?->payload)->toBe([])
        ->and($replayable->fresh()?->payload)->toBe(['email' => 'pending@example.com'])
        ->and($previouslyApplied->fresh()?->payload)->toBe([]);
});

it('counts a redelivery on the same row instead of adding one', function (): void {
    Organization::factory()->create(['stripe_id' => 'cus_acme']);
    $this->travelTo('2026-09-25 10:00:00');
    StripeWebhook::post(StripeWebhook::subscriptionPayload(created: 1_000))->assertOk();
    $this->travelTo('2026-09-25 10:05:00');

    StripeWebhook::post(StripeWebhook::subscriptionPayload(created: 1_000))->assertOk();

    expect(WebhookEvent::query()->sole())
        ->deliveries->toBe(2)
        ->first_received_at->toDateTimeString()->toBe('2026-09-25 10:00:00')
        ->last_received_at->toDateTimeString()->toBe('2026-09-25 10:05:00')
        ->applied_at->toDateTimeString()->toBe('2026-09-25 10:00:00')
        ->payload->toBe([]);
});

it('moves an errored event to applied when Stripe redelivers it successfully', function (): void {
    Organization::factory()->create(['stripe_id' => 'cus_acme']);
    $failing = true;
    Event::listen(WebhookReceived::class, static function () use (&$failing): void {
        throw_if($failing, RuntimeException::class, 'The database is temporarily unavailable.');
    });
    StripeWebhook::post(StripeWebhook::subscriptionPayload())->assertServerError();
    $failing = false;

    StripeWebhook::post(StripeWebhook::subscriptionPayload())->assertOk();

    expect(WebhookEvent::query()->sole())
        ->outcome->toBe(WebhookOutcome::Applied)
        ->outcome_reason->toBeNull()
        ->deliveries->toBe(2)
        ->applied_at->not->toBeNull();
});

it('keeps the time an event applied when a later redelivery is superseded', function (): void {
    Organization::factory()->create(['stripe_id' => 'cus_acme']);
    $this->travelTo('2026-09-25 10:00:00');
    StripeWebhook::post(StripeWebhook::subscriptionPayload(created: 1_000))->assertOk();
    StripeWebhook::post(StripeWebhook::subscriptionPayload(
        eventId: 'evt_subscription_updated',
        type: 'customer.subscription.updated',
        created: 2_000,
    ))->assertOk();
    $this->travelTo('2026-09-25 11:00:00');

    StripeWebhook::post(StripeWebhook::subscriptionPayload(created: 1_000))
        ->assertOk()
        ->assertContent('Webhook superseded.');

    expect(WebhookEvent::query()->where('stripe_event_id', 'evt_subscription_created')->sole())
        ->outcome->toBe(WebhookOutcome::Superseded)
        ->applied_at->toDateTimeString()->toBe('2026-09-25 10:00:00')
        ->payload->toBe([]);
});

it('records each delivery of an event without an id on its own row', function (): void {
    $payload = ['type' => 'ping', 'data' => ['object' => []]];

    StripeWebhook::post($payload)->assertOk();
    StripeWebhook::post($payload)->assertOk();

    expect(WebhookEvent::query()->whereNull('stripe_event_id')->count())->toBe(2);
});

it('prunes each outcome after its own retention window', function (): void {
    $kept = [
        WebhookEvent::factory()->receivedDaysAgo(89)->create(),
        WebhookEvent::factory()->unplaceable()->receivedDaysAgo(179)->create(),
    ];
    WebhookEvent::factory()->receivedDaysAgo(91)->create();
    WebhookEvent::factory()->receivedDaysAgo(91)->create(['outcome' => WebhookOutcome::Superseded]);
    WebhookEvent::factory()->unplaceable()->receivedDaysAgo(181)->create();
    WebhookEvent::factory()->receivedDaysAgo(181)->create(['outcome' => WebhookOutcome::Errored, 'applied_at' => null]);

    Artisan::call('model:prune', ['--model' => [WebhookEvent::class]]);

    expect(WebhookEvent::query()->orderBy('id')->pluck('id')->all())
        ->toBe(array_map(fn (WebhookEvent $event): int => $event->id, $kept));
});

it('schedules the pruning daily', function (): void {
    $events = collect(resolve(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'model:prune')
            && str_contains((string) $event->command, 'WebhookEvent'));

    expect($events)->toHaveCount(1)
        ->and($events->sole()->expression)->toBe('0 0 * * *');
});

it('folds retained failed events into one row per event when migrating', function (): void {
    $migration = 'database/migrations/2026_09_25_094307_fold_failed_webhook_events_into_webhook_events.php';
    Artisan::call('migrate:rollback', ['--path' => $migration, '--force' => true]);
    $retained = fn (?string $eventId, string $reason, string $receivedAt): array => [
        'stripe_event_id' => $eventId,
        'type' => 'customer.subscription.updated',
        'stripe_customer_id' => 'cus_gone',
        'payload' => json_encode(['id' => $eventId, 'created' => 1_000, 'data' => ['object' => ['id' => 'sub_gone', 'customer' => 'cus_gone']]]),
        'reason' => $reason,
        'message' => $reason.' at '.$receivedAt,
        'created_at' => $receivedAt,
    ];
    DB::table('failed_webhook_events')->insert([
        $retained('evt_twice', 'OrganizationNotFound', '2026-09-20 10:00:00'),
        $retained('evt_twice', 'TenantContextMissing', '2026-09-21 10:00:00'),
        $retained('evt_stale', 'SupersededDelivery', '2026-09-22 10:00:00'),
        $retained(null, 'OrganizationNotFound', '2026-09-23 10:00:00'),
        $retained(null, 'OrganizationNotFound', '2026-09-24 10:00:00'),
    ]);

    Artisan::call('migrate', ['--path' => $migration, '--force' => true]);

    $twice = WebhookEvent::query()->where('stripe_event_id', 'evt_twice')->sole();
    $stale = WebhookEvent::query()->where('stripe_event_id', 'evt_stale')->sole();
    expect(DB::getSchemaBuilder()->hasTable('failed_webhook_events'))->toBeFalse()
        ->and(WebhookEvent::query()->whereNull('stripe_event_id')->count())->toBe(2)
        ->and($twice->outcome)->toBe(WebhookOutcome::Unplaceable)
        ->and($twice->outcome_reason)->toBe('TenantContextMissing')
        ->and($twice->deliveries)->toBe(2)
        ->and($twice->stripe_object_id)->toBe('sub_gone')
        ->and($twice->stripe_created_at)->toBe(1_000)
        ->and($twice->applied_at)->toBeNull()
        ->and($twice->first_received_at->toDateTimeString())->toBe('2026-09-20 10:00:00')
        ->and($twice->last_received_at->toDateTimeString())->toBe('2026-09-21 10:00:00')
        ->and($stale->outcome)->toBe(WebhookOutcome::Superseded)
        ->and($stale->deliveries)->toBe(1);
});
