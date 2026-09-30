<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\RecordAuditEvent;
use App\Actions\RevokeOrganizationInvitation;
use App\Audit\AuditActor;
use App\Enums\AuditAction;
use App\Enums\MembershipRank;
use App\Enums\WebhookOutcome;
use App\Models\Impersonation;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Operations\OrganizationActivity;
use App\Operations\SubscriptionTimeline;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Tests\Support\StripeWebhook;

it('lists members with the owner marked', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $bob = User::factory()->create(['name' => 'Bob']);
    resolve(AddOrganizationMember::class)->handle($organization, $bob, MembershipRank::Member);

    $members = resolve(OrganizationActivity::class)->members($organization);

    expect(array_column($members, 'user_id'))->toBe([$owner->id, $bob->id])
        ->and(array_column($members, 'owner'))->toBe([true, false])
        ->and($members[1])->name->toBe('Bob')->rank->toBe('member');
});

it('lists only invitations still waiting for an answer', function (): void {
    [$organization] = organizationOwnedBySomeone();
    issueInvitation($organization, 'waiting@example.com');
    $revoked = findInvitation(issueInvitation($organization, 'gone@example.com'));
    resolve(RevokeOrganizationInvitation::class)->handle($revoked);

    $invitations = resolve(OrganizationActivity::class)->pendingInvitations($organization);

    expect(array_column($invitations, 'email'))->toBe(['waiting@example.com']);
});

it('pages the audit log newest first and names the operator behind an impersonated act', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $operator = User::factory()->create(['name' => 'Carol']);
    $impersonation = Impersonation::factory()->create(['operator_id' => $operator->id, 'user_id' => $owner->id]);
    $audit = resolve(RecordAuditEvent::class);
    $audit->handle($organization->id, AuditAction::InvitationSent);
    $this->travel(1)->minutes();
    AuditActor::runAs(AuditActor::user($owner, $impersonation->id), fn () => $audit->handle($organization->id, AuditAction::MemberRemoved));

    $page = resolve(OrganizationActivity::class)->auditLog($organization, page: 1, perPage: 1);

    expect($page['total'])->toBe(2)
        ->and($page['rows'])->toHaveCount(1)
        ->and($page['rows'][0])
        ->action->toBe(AuditAction::MemberRemoved->value)
        ->actor->toBe($owner->name)
        ->impersonated_by->toBe('Carol');
});

it('shows the organization Stripe events newest first and marks the one behind the current state', function (): void {
    config(['cashier.webhook.secret' => StripeWebhook::SECRET]);
    $organization = Organization::factory()->create(['stripe_id' => 'cus_acme']);
    StripeWebhook::post(StripeWebhook::subscriptionPayload(created: 1_000))->assertOk();
    StripeWebhook::post(StripeWebhook::subscriptionPayload(eventId: 'evt_updated', type: 'customer.subscription.updated', created: 2_000))->assertOk();
    StripeWebhook::post(StripeWebhook::subscriptionPayload(eventId: 'evt_stale', type: 'customer.subscription.updated', created: 1_500))->assertOk();
    WebhookEvent::factory()->create(['stripe_customer_id' => 'cus_other']);

    $events = resolve(SubscriptionTimeline::class)->events($organization);

    expect($events['total'])->toBe(3)
        ->and(array_column($events['rows'], 'stripe_event_id'))->toBe(['evt_updated', 'evt_stale', 'evt_subscription_created'])
        ->and(array_column($events['rows'], 'wrote_current_state'))->toBe([true, false, false])
        ->and($events['rows'][1]['outcome'])->toBe(WebhookOutcome::Superseded->value);
});

it('names the event that wrote the current subscription, whatever asked for a refresh since', function (): void {
    config(['cashier.webhook.secret' => StripeWebhook::SECRET]);
    $organization = Organization::factory()->create(['stripe_id' => 'cus_acme']);
    StripeWebhook::post(StripeWebhook::subscriptionPayload(created: 1_000))->assertOk();
    StripeWebhook::post(StripeWebhook::subscriptionPayload(eventId: 'evt_updated', type: 'customer.subscription.updated', created: 2_000))->assertOk();
    StripeWebhook::post(StripeWebhook::subscriptionPayload(eventId: 'evt_stale', type: 'customer.subscription.updated', created: 1_500))->assertOk();
    resolve(NativeStateStore::class)->request(organizationEntitlementOwner($organization), Date::now()->toDateTimeImmutable());

    expect(resolve(SubscriptionTimeline::class)->currentStateEvent($organization))
        ->stripe_event_id->toBe('evt_updated')
        ->type->toBe('customer.subscription.updated')
        ->applied_at->not->toBeNull();
});

it('names no event when Stripe has never changed the subscription', function (): void {
    $timeline = resolve(SubscriptionTimeline::class);

    expect($timeline->currentStateEvent(Organization::factory()->create()))->toBeNull()
        ->and($timeline->currentStateEvent(Organization::factory()->create(['stripe_id' => 'cus_quiet'])))->toBeNull();
});

it('shows no events for an organization Stripe has never seen', function (): void {
    WebhookEvent::factory()->create(['stripe_customer_id' => null]);

    expect(resolve(SubscriptionTimeline::class)->events(Organization::factory()->create()))->toBe(['rows' => [], 'total' => 0]);
});

it('summarises the subscription as it stands', function (): void {
    config(['cashier.webhook.secret' => StripeWebhook::SECRET]);
    $organization = Organization::factory()->create(['stripe_id' => 'cus_acme']);
    StripeWebhook::post(StripeWebhook::subscriptionPayload())->assertOk();

    expect(resolve(SubscriptionTimeline::class)->current($organization->fresh()))
        ->state->toBe('active')
        ->stripe_status->toBe('active');
});
