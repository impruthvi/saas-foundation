<?php

declare(strict_types=1);

use App\Actions\ConsumePendingInvitation;
use App\Enums\MembershipRole;
use App\Mail\OrganizationInvitation;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TenantQueryGuard;

/*
|--------------------------------------------------------------------------
| "Invite a teammate" — M2's segment of the ten-minute journey
|--------------------------------------------------------------------------
|
| D8's sentence, second clause. A milestone is not done when its code exists; it
| is done when its segment of this journey runs. Everything else in M2 is a unit
| of this test.
|
|   register ──► personal organization exists (D1)
|        │
|        ▼
|   invite b@example.com ──► email queued, carrying the only usable token
|        │
|        ▼
|   B opens the link as a stranger ──► token parked in the session
|        │
|        ▼
|   B registers ──► gets their OWN personal organization (D1 is unconditional)
|        │           and the parked invitation is spent
|        ▼
|   B is an active member of two organizations, which is exactly when the
|   workspace switcher stops being hidden.
|
*/

it('takes a stranger from an invitation link to membership of two organizations', function (): void {
    Mail::fake();

    // A registers, and D1 gives them somewhere to own.
    $this->post(route('register.store'), [
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => 'password-that-is-long',
        'password_confirmation' => 'password-that-is-long',
    ])->assertRedirect();

    $ada = User::query()->where('email', 'ada@example.com')->sole();
    $acme = resolve(MembershipRepository::class)->organizationsFor($ada)->sole();

    expect($acme->personal)->toBeTrue();

    // A invites B.
    $this->actingAs($ada)
        ->post(route('organizations.invitations.store'), [
            'email' => 'grace@example.com',
            'role' => MembershipRole::Member->value,
        ])
        ->assertRedirect();

    $token = null;

    Mail::assertQueued(OrganizationInvitation::class, function (OrganizationInvitation $mail) use (&$token): bool {
        // The link is the only place the plaintext token survives, so the test
        // reads it the way the recipient does rather than from the database.
        preg_match('#/invitations/([^/?\s]+)#', $mail->acceptUrl, $matches);
        $token = $matches[1] ?? null;

        return $mail->hasTo('grace@example.com');
    });

    expect($token)->toBeString();

    // B opens the link with no account and nobody signed in.
    $this->post(route('logout'));

    throughTheAuditedDoor(fn () => $this->get(route('invitations.show', ['token' => $token]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('organization', $acme->name)
            ->where('email', 'grace@example.com')
            ->where('authenticated', false)));

    expect(session(ConsumePendingInvitation::SESSION_KEY))->toBe($token);

    // B registers. The parked invitation is spent on the way through.
    throughTheAuditedDoor(fn () => $this->post(route('register.store'), [
        'name' => 'Grace',
        'email' => 'grace@example.com',
        'password' => 'password-that-is-long',
        'password_confirmation' => 'password-that-is-long',
    ])->assertRedirect());

    $grace = User::query()->where('email', 'grace@example.com')->sole();

    $organizations = resolve(MembershipRepository::class)->organizationsFor($grace);

    expect($organizations)->toHaveCount(2)
        // D1 is unconditional: being invited somewhere does not replace having
        // somewhere of your own.
        ->and($organizations->firstWhere('personal', true))->not->toBeNull()
        ->and($organizations->pluck('id'))->toContain($acme->id)
        // Two organizations is the moment the switcher stops being hidden.
        ->and($organizations->count())->toBeGreaterThan(1);

    $invitation = resolve(TenantContext::class)->runFor(
        $acme,
        fn (): Invitation => Invitation::query()->sole(),
    );

    expect($invitation->accepted_by_user_id)->toBe($grace->id)
        ->and(session(ConsumePendingInvitation::SESSION_KEY))->toBeNull();
});

it('still registers the account when the parked invitation has lapsed', function (): void {
    Mail::fake();

    $organization = Organization::factory()->create();
    $token = issueInvitation($organization, 'slow@example.com');

    throughTheAuditedDoor(fn () => $this->get(route('invitations.show', ['token' => $token])));

    $this->travel(8)->days();

    // An invitation that expired while somebody filled in a form must not cost
    // them their account. They can ask for a new invitation; they cannot ask
    // for their registration back.
    throughTheAuditedDoor(fn () => $this->post(route('register.store'), [
        'name' => 'Slow',
        'email' => 'slow@example.com',
        'password' => 'password-that-is-long',
        'password_confirmation' => 'password-that-is-long',
    ])->assertRedirect());

    $user = User::query()->where('email', 'slow@example.com')->sole();

    expect(resolve(MembershipRepository::class)->organizationsFor($user))->toHaveCount(1);
});

it('does not let a parked token admit whoever signs up next', function (): void {
    Mail::fake();

    $organization = Organization::factory()->create();
    $token = issueInvitation($organization, 'intended@example.com');

    throughTheAuditedDoor(fn () => $this->get(route('invitations.show', ['token' => $token])));

    // Same browser, same session, different person entirely.
    throughTheAuditedDoor(fn () => $this->post(route('register.store'), [
        'name' => 'Interloper',
        'email' => 'interloper@example.com',
        'password' => 'password-that-is-long',
        'password_confirmation' => 'password-that-is-long',
    ])->assertRedirect());

    $interloper = User::query()->where('email', 'interloper@example.com')->sole();

    expect(resolve(MembershipRepository::class)->organizationsFor($interloper))->toHaveCount(1)
        ->and(session(ConsumePendingInvitation::SESSION_KEY))->toBeNull();

    $invitation = TenantQueryGuard::allowUnscoped(
        fn (): Invitation => Invitation::query()->withoutGlobalScopes()->sole(),
    );

    expect($invitation->accepted_by_user_id)->toBeNull();
});
