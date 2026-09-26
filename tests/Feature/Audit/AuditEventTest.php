<?php

declare(strict_types=1);

use App\Actions\AcceptOrganizationInvitation;
use App\Actions\AddOrganizationMember;
use App\Actions\CancelSubscription;
use App\Actions\ChangeOrganizationMemberRole;
use App\Actions\ConsumePendingInvitation;
use App\Actions\CreateOrganization;
use App\Actions\DeclineOrganizationInvitation;
use App\Actions\RecordAuditEvent;
use App\Actions\ResendOrganizationInvitation;
use App\Actions\ResumeSubscription;
use App\Actions\RevokeOrganizationInvitation;
use App\Actions\StartBillingCheckout;
use App\Actions\TransferOrganizationOwnership;
use App\Audit\AuditActor;
use App\Billing\PlanCatalog;
use App\Billing\Price;
use App\Enums\AuditAction;
use App\Enums\AuditSource;
use App\Enums\MembershipRole;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\AuditEvent;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Stripe\StripeClient;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Fixtures\RecordAuditedAct;
use Tests\Support\FakeStripeClient;

/**
 * @return Collection<int, AuditEvent>
 */
function auditTrailOf(Organization $organization): Collection
{
    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Collection => AuditEvent::query()->orderBy('id')->get(),
    );
}

function pendingInvitationFor(Organization $organization, string $email): Invitation
{
    $token = issueInvitation($organization, $email);

    /** @var Invitation */
    return findInvitation($token);
}

function activeSubscriptionFor(Organization $organization, ?DateTimeInterface $endsAt = null): Subscription
{
    return resolve(TenantContext::class)->runFor($organization, function () use ($organization, $endsAt): Subscription {
        $subscription = Subscription::query()->create([
            'type' => 'default',
            'stripe_id' => 'sub_audit_'.$organization->id,
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
            'ends_at' => $endsAt,
        ]);

        $subscription->items()->create([
            'stripe_id' => 'si_audit_'.$organization->id,
            'stripe_product' => 'prod_pro',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]);

        return $subscription;
    });
}

function fakeStripeForAudit(): FakeStripeClient
{
    $stripe = new FakeStripeClient();
    $stripe->subscriptionPeriodEnd = now()->addMonth()->getTimestamp();

    app()->bind(StripeClient::class, fn (): StripeClient => $stripe);

    return $stripe;
}

it('records who removed a member over HTTP', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $member);

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->delete(route('organizations.members.destroy', $membership))
        ->assertRedirect();

    expect(auditTrailOf($organization)->sole())
        ->action->toBe(AuditAction::MemberRemoved)
        ->actor_id->toBe($owner->id)
        ->source->toBe(AuditSource::Web)
        ->subject_type->toBe($membership->getMorphClass())
        ->subject_id->toBe($membership->id)
        ->context->toBe(['user_id' => $member->id, 'rank' => MembershipRole::Member->value]);
});

it('records each invitation act with the address it concerned', function (Closure $act, AuditAction $expected): void {
    Mail::fake();
    [$organization, $owner] = organizationOwnedBySomeone();

    AuditActor::runAs(AuditActor::user($owner), fn () => $act($organization));

    expect(auditTrailOf($organization)->last())
        ->action->toBe($expected)
        ->actor_id->toBe($owner->id)
        ->context->email->toBe('bob@example.com');
})->with([
    'sent' => [fn (Organization $organization): string => issueInvitation($organization, 'bob@example.com'), AuditAction::InvitationSent],
    'resent' => [fn (Organization $organization) => resolve(ResendOrganizationInvitation::class)->handle(pendingInvitationFor($organization, 'bob@example.com')), AuditAction::InvitationResent],
    'revoked' => [fn (Organization $organization) => resolve(RevokeOrganizationInvitation::class)->handle(pendingInvitationFor($organization, 'bob@example.com')), AuditAction::InvitationRevoked],
    'declined' => [fn (Organization $organization) => resolve(DeclineOrganizationInvitation::class)->handle(pendingInvitationFor($organization, 'bob@example.com')), AuditAction::InvitationDeclined],
]);

it('records an accepted invitation under the inviting organization', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $invitation = pendingInvitationFor($organization, 'bob@example.com');
    $bob = User::factory()->create(['email' => 'bob@example.com']);
    resolve(CreateOrganization::class)->handle($bob, 'Bob', personal: true);

    AuditActor::runAs(AuditActor::user($bob), fn () => resolve(AcceptOrganizationInvitation::class)->handle($invitation, $bob));

    expect(auditTrailOf($organization)->last())
        ->action->toBe(AuditAction::InvitationAccepted)
        ->actor_id->toBe($bob->id)
        ->context->toBe(['email' => 'bob@example.com', 'user_id' => $bob->id, 'rank' => MembershipRole::Member->value]);
});

it('names the new account as the actor when sign-up accepts a parked invitation', function (): void {
    Mail::fake();
    [$organization] = organizationOwnedBySomeone();
    $token = issueInvitation($organization, 'new@example.com');

    throughTheAuditedDoor(fn () => $this->withSession([ConsumePendingInvitation::SESSION_KEY => $token])
        ->post(route('register.store'), [
            'name' => 'New',
            'email' => 'new@example.com',
            'password' => 'password-that-is-long',
            'password_confirmation' => 'password-that-is-long',
        ])->assertRedirect());

    $newcomer = User::query()->where('email', 'new@example.com')->sole();

    expect(auditTrailOf($organization)->last())
        ->action->toBe(AuditAction::InvitationAccepted)
        ->actor_id->toBe($newcomer->id)
        ->source->toBe(AuditSource::Web);
});

it('records a rank change with where it moved from and to', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $member);

    AuditActor::runAs(AuditActor::user($owner), fn () => resolve(ChangeOrganizationMemberRole::class)->handle($membership, MembershipRole::Admin));

    expect(auditTrailOf($organization)->sole())
        ->action->toBe(AuditAction::MemberRankChanged)
        ->context->toBe(['user_id' => $member->id, 'from' => 'member', 'to' => 'admin']);
});

it('records an ownership transfer with both owners', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $successor = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $successor);

    AuditActor::runAs(AuditActor::user($owner), fn () => resolve(TransferOrganizationOwnership::class)->handle($organization, $successor));

    expect(auditTrailOf($organization)->sole())
        ->action->toBe(AuditAction::OwnershipTransferred)
        ->context->toBe(['from_user_id' => $owner->id, 'to_user_id' => $successor->id]);
});

it('records the billing acts that reach Stripe', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    fakeStripeForAudit();
    $price = resolve(PlanCatalog::class)->findPlan('pro')?->prices[0];
    expect($price)->toBeInstanceOf(Price::class);

    AuditActor::runAs(AuditActor::user($owner), function () use ($organization, $price): void {
        resolve(TenantContext::class)->runFor($organization, fn () => resolve(StartBillingCheckout::class)
            ->handle($organization, $price, 'https://example.test/ok', 'https://example.test/cancel'));
        activeSubscriptionFor($organization);
        resolve(TenantContext::class)->runFor($organization, fn () => resolve(CancelSubscription::class)->handle($organization->fresh()));
        resolve(TenantContext::class)->runFor($organization, fn () => resolve(ResumeSubscription::class)->handle($organization->fresh()));
    });

    expect(auditTrailOf($organization)->pluck('action')->all())->toBe([
        AuditAction::CheckoutStarted,
        AuditAction::SubscriptionCancelled,
        AuditAction::SubscriptionResumed,
    ]);
});

it('carries the actor into a queued act and forgets it for the next job', function (): void {
    config()->set('queue.default', 'database');
    [$organization, $owner] = organizationOwnedBySomeone();

    AuditActor::runAs(AuditActor::user($owner, impersonationId: 7), function () use ($organization): void {
        dispatch(new RecordAuditedAct($organization->id, 'carried'));
    });
    dispatch(new RecordAuditedAct($organization->id, 'anonymous'));

    $this->artisan('queue:work --once')->assertSuccessful();
    $this->artisan('queue:work --once')->assertSuccessful();

    [$carried, $anonymous] = auditTrailOf($organization)->all();
    expect($carried)
        ->actor_id->toBe($owner->id)
        ->impersonation_id->toBe(7)
        ->source->toBe(AuditSource::Web)
        ->and($anonymous)
        ->actor_id->toBeNull()
        ->source->toBe(AuditSource::System);
});

it('records an act started from the command line as the console', function (): void {
    [$organization] = organizationOwnedBySomeone();

    // The console kernel fires this for every command outside the test suite.
    event(new CommandStarting('app:anything', new ArrayInput([]), new NullOutput()));

    resolve(RecordAuditEvent::class)->handle($organization->id, AuditAction::MemberRemoved);

    expect(auditTrailOf($organization)->sole())
        ->actor_id->toBeNull()
        ->source->toBe(AuditSource::Console);
});

it('refuses to change or delete a recorded event', function (string $write): void {
    [$organization] = organizationOwnedBySomeone();
    $event = resolve(RecordAuditEvent::class)->handle($organization->id, AuditAction::MemberRemoved);

    resolve(TenantContext::class)->runFor($organization, fn () => $write === 'update'
        ? $event->update(['context' => ['edited' => true]])
        : $event->delete());
})->with(['update', 'delete'])->throws(LogicException::class);

it('keeps one organization out of another organization audit log', function (): void {
    [$acme] = organizationOwnedBySomeone('Acme');
    [$globex] = organizationOwnedBySomeone('Globex');

    resolve(RecordAuditEvent::class)->handle($acme->id, AuditAction::MemberRemoved);

    expect(auditTrailOf($globex))->toBeEmpty()
        ->and(auditTrailOf($acme))->toHaveCount(1);
});

it('deletes an organization audit log with the organization', function (): void {
    $organization = Organization::factory()->create();
    resolve(RecordAuditEvent::class)->handle($organization->id, AuditAction::MemberRemoved);

    $organization->delete();

    expect(auditTrailOf($organization))->toBeEmpty();
});
