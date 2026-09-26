<?php

declare(strict_types=1);

use App\Actions\ReplayWebhookEvent;
use App\Actions\RequestEntitlementRefresh;
use App\Audit\AuditActor;
use App\Enums\AuditAction;
use App\Enums\WebhookOutcome;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Impruthvi\CashierEntitlements\Jobs\RefreshOwner;
use Laravel\Cashier\Events\WebhookHandled;
use Tests\Support\StripeWebhook;

beforeEach(function (): void {
    config(['cashier.webhook.secret' => StripeWebhook::SECRET]);
});

/**
 * Deliver an event for a customer no organization holds yet, and keep its row.
 *
 * @param  array<string, mixed>  $overrides
 */
function unplacedEvent(array $overrides = []): WebhookEvent
{
    StripeWebhook::post([...StripeWebhook::subscriptionPayload(customerId: 'cus_late', created: 1_000), ...$overrides])->assertOk();

    return WebhookEvent::query()->latest('id')->firstOrFail();
}

/**
 * @return list<AuditEvent>
 */
function replayAudit(Organization $organization): array
{
    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): array => AuditEvent::query()->where('action', AuditAction::WebhookReplayed)->get()->all(),
    );
}

it('applies an event once its organization exists and asks for a refresh itself', function (): void {
    $event = unplacedEvent();
    $organization = Organization::factory()->create(['stripe_id' => 'cus_late']);
    $operator = User::factory()->create();
    Bus::fake([RefreshOwner::class]);
    Event::fake([WebhookHandled::class]);

    $outcome = AuditActor::runAs(AuditActor::user($operator), fn (): WebhookOutcome => resolve(ReplayWebhookEvent::class)->handle($event));

    expect($outcome)->toBe(WebhookOutcome::Replayed)
        ->and($event->fresh())->outcome->toBe(WebhookOutcome::Replayed)->applied_at->not->toBeNull()
        ->and(resolve(TenantContext::class)->runFor($organization, fn (): bool => Subscription::query()->where('stripe_id', 'sub_pro')->exists()))->toBeTrue();
    Bus::assertDispatched(RefreshOwner::class);
    Event::assertNotDispatched(WebhookHandled::class);
    expect(replayAudit($organization))->toHaveCount(1)
        ->and(replayAudit($organization)[0]->actor_id)->toBe($operator->id);
});

it('leaves an event unplaceable when its organization still does not exist', function (): void {
    $event = unplacedEvent();
    Bus::fake([RefreshOwner::class]);

    $outcome = resolve(ReplayWebhookEvent::class)->handle($event);

    expect($outcome)->toBe(WebhookOutcome::Unplaceable)
        ->and($event->fresh())->outcome->toBe(WebhookOutcome::Unplaceable)->deliveries->toBe(2);
    Bus::assertNotDispatched(RefreshOwner::class);
});

it('does not apply an event a newer one has overtaken', function (): void {
    $event = unplacedEvent();
    Organization::factory()->create(['stripe_id' => 'cus_late']);
    StripeWebhook::post(StripeWebhook::subscriptionPayload(eventId: 'evt_newer', type: 'customer.subscription.updated', customerId: 'cus_late', created: 2_000))->assertOk();
    Bus::fake([RefreshOwner::class]);

    expect(resolve(ReplayWebhookEvent::class)->handle($event))->toBe(WebhookOutcome::Superseded);

    Bus::assertNotDispatched(RefreshOwner::class);
});

it('reports a replay whose handler fails as errored', function (): void {
    $event = unplacedEvent(['data' => ['object' => ['id' => 'sub_broken', 'customer' => 'cus_late']]]);
    Organization::factory()->create(['stripe_id' => 'cus_late']);

    expect(resolve(ReplayWebhookEvent::class)->handle($event))->toBe(WebhookOutcome::Errored)
        ->and($event->fresh()?->outcome)->toBe(WebhookOutcome::Errored);
});

it('applies a customer deletion without asking for a refresh that could not run', function (): void {
    StripeWebhook::post(['id' => 'evt_customer_deleted', 'type' => 'customer.deleted', 'livemode' => false, 'data' => ['object' => ['id' => 'cus_late']]])->assertOk();
    $event = WebhookEvent::query()->where('stripe_event_id', 'evt_customer_deleted')->sole();
    $organization = Organization::factory()->create(['stripe_id' => 'cus_late']);
    Bus::fake([RefreshOwner::class]);

    expect(resolve(ReplayWebhookEvent::class)->handle($event))->toBe(WebhookOutcome::Replayed)
        ->and($organization->fresh()?->stripe_id)->toBeNull();
    Bus::assertNotDispatched(RefreshOwner::class);
});

it('asks for no refresh after an event type that does not affect entitlements', function (): void {
    StripeWebhook::post(['id' => 'evt_invoice_created', 'type' => 'invoice.created', 'livemode' => false, 'data' => ['object' => ['id' => 'in_1', 'customer' => 'cus_late']]])->assertOk();
    $event = WebhookEvent::query()->where('stripe_event_id', 'evt_invoice_created')->sole();
    Organization::factory()->create(['stripe_id' => 'cus_late']);
    Bus::fake([RefreshOwner::class]);

    expect(resolve(ReplayWebhookEvent::class)->handle($event))->toBe(WebhookOutcome::Replayed);

    Bus::assertNotDispatched(RefreshOwner::class);
});

it('refuses an event from another Stripe mode without applying it', function (): void {
    $event = unplacedEvent(['livemode' => true]);
    $organization = Organization::factory()->create(['stripe_id' => 'cus_late']);

    expect(resolve(ReplayWebhookEvent::class)->handle($event))->toBe(WebhookOutcome::Refused)
        ->and($event->fresh())->outcome->toBe(WebhookOutcome::Refused)->outcome_reason->toBe('ContextMismatch')
        ->and(resolve(TenantContext::class)->runFor($organization, fn (): bool => Subscription::query()->exists()))->toBeFalse();
});

it('refuses to replay an event that already applied', function (): void {
    $event = WebhookEvent::factory()->create();

    resolve(ReplayWebhookEvent::class)->handle($event);
})->throws(InvalidArgumentException::class, 'Only an event that was never applied can be replayed.');

it('refreshes on the same event types as the entitlement package', function (): void {
    $listener = (string) file_get_contents(base_path('vendor/impruthvi/cashier-entitlements/src/Listeners/QueueRefreshFromWebhook.php'));
    preg_match("/in_array\\(\\\$payload\\['type'\\] \\?\\? null, \\[(.*?)\\], true\\)/s", $listener, $list);
    preg_match_all("/'([a-z._]+)'/", $list[1] ?? '', $types);

    expect($types[1])->toBe(ReplayWebhookEvent::REFRESHING_EVENTS);
});

it('asks for a refresh on an operator request and audits who asked', function (): void {
    $organization = Organization::factory()->create();
    $operator = User::factory()->create();
    Bus::fake([RefreshOwner::class]);

    AuditActor::runAs(AuditActor::user($operator), fn () => resolve(RequestEntitlementRefresh::class)->handle($organization));

    Bus::assertDispatched(RefreshOwner::class);
    $audited = resolve(TenantContext::class)->runFor($organization, fn (): AuditEvent => AuditEvent::query()->sole());
    expect($audited)->action->toBe(AuditAction::EntitlementRefreshRequested)->actor_id->toBe($operator->id);
});
