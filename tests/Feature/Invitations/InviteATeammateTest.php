<?php

declare(strict_types=1);

use App\Actions\ConsumePendingInvitation;
use App\Actions\CreatePersonalOrganization;
use App\Enums\MembershipRole;
use App\Mail\OrganizationInvitation;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Tests\Support\TenantQueryGuard;

it('takes a stranger from an invitation link to membership of two organizations', function (): void {
    Mail::fake();

    $this->post(route('register.store'), [
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => 'password-that-is-long',
        'password_confirmation' => 'password-that-is-long',
    ])->assertRedirect();

    $ada = User::query()->where('email', 'ada@example.com')->sole();
    $acme = resolve(MembershipRepository::class)->organizationsFor($ada)->sole();

    expect($acme->personal)->toBeTrue();

    $this->actingAs($ada)
        ->post(route('organizations.invitations.store'), [
            'email' => 'grace@example.com',
            'role' => MembershipRole::Member->value,
        ])
        ->assertRedirect();

    $token = null;

    Mail::assertQueued(OrganizationInvitation::class, function (OrganizationInvitation $mail) use (&$token): bool {
        // The link is the only place the plaintext token survives, so it is read the
        // way the recipient reads it.
        preg_match('#/invitations/([^/?\s]+)#', $mail->acceptUrl, $matches);
        $token = $matches[1] ?? null;

        return $mail->hasTo('grace@example.com');
    });

    expect($token)->toBeString();

    $this->post(route('logout'));

    throughTheAuditedDoor(fn () => $this->get(route('invitations.show', ['token' => $token]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('organization', $acme->name)
            ->where('email', 'grace@example.com')
            ->where('authenticated', false)));

    expect(session(ConsumePendingInvitation::SESSION_KEY))->toBe($token);

    throughTheAuditedDoor(fn () => $this->post(route('register.store'), [
        'name' => 'Grace',
        'email' => 'grace@example.com',
        'password' => 'password-that-is-long',
        'password_confirmation' => 'password-that-is-long',
    ])->assertRedirect());

    $grace = User::query()->where('email', 'grace@example.com')->sole();

    $organizations = resolve(MembershipRepository::class)->organizationsFor($grace);

    expect($organizations)->toHaveCount(2)
        ->and($organizations->firstWhere('personal', true))->not->toBeNull()
        ->and($organizations->pluck('id'))->toContain($acme->id)
        ->and($organizations->count())->toBeGreaterThan(1);

    $invitation = resolve(TenantContext::class)->runFor(
        $acme,
        fn (): Invitation => Invitation::query()->sole(),
    );

    expect($invitation->accepted_by_user_id)->toBe($grace->id)
        ->and(session(ConsumePendingInvitation::SESSION_KEY))->toBeNull();
});

it('accepts a parked invitation when its addressee signs in instead of registering', function (): void {
    $organization = Organization::factory()->create();
    $token = issueInvitation($organization, 'returning@example.com');
    $returning = User::factory()->create(['email' => 'returning@example.com']);
    resolve(CreatePersonalOrganization::class)->handle($returning);

    throughTheAuditedDoor(fn () => $this->get(route('invitations.show', ['token' => $token])));

    throughTheAuditedDoor(fn () => $this->post(route('login.store'), [
        'email' => 'returning@example.com',
        'password' => 'password',
    ])->assertRedirect());

    expect(resolve(MembershipRepository::class)->organizationsFor($returning)->pluck('id'))->toContain($organization->id)
        ->and(session(ConsumePendingInvitation::SESSION_KEY))->toBeNull();
});

it('does not let a parked token admit whoever signs in next', function (): void {
    $organization = Organization::factory()->create();
    $token = issueInvitation($organization, 'intended@example.com');
    $other = User::factory()->create(['email' => 'other@example.com']);

    throughTheAuditedDoor(fn () => $this->get(route('invitations.show', ['token' => $token])));

    throughTheAuditedDoor(fn () => $this->post(route('login.store'), [
        'email' => 'other@example.com',
        'password' => 'password',
    ])->assertRedirect());

    expect(resolve(MembershipRepository::class)->organizationsFor($other)->pluck('id'))->not->toContain($organization->id)
        ->and(session(ConsumePendingInvitation::SESSION_KEY))->toBeNull();
});

it('still registers the account when the parked invitation has lapsed', function (): void {
    Mail::fake();

    $organization = Organization::factory()->create();
    $token = issueInvitation($organization, 'slow@example.com');

    throughTheAuditedDoor(fn () => $this->get(route('invitations.show', ['token' => $token])));

    $this->travel(8)->days();

    // An invitation that expired mid-form must not cost somebody their account.
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

    // Same browser, same session, different person.
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

it('locks the register form to the address the invitation names', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $token = issueInvitation($organization, 'fixed@example.com');

    throughTheAuditedDoor(fn () => $this->get(route('invitations.show', ['token' => $token])));

    throughTheAuditedDoor(fn () => $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('invitation.email', 'fixed@example.com')
            ->where('invitation.organization', $organization->name)));
});

it('offers no invitation to the register form when none is parked', function (): void {
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('invitation', null));
});

it('says so when a parked invitation could not be taken', function (): void {
    $organization = Organization::factory()->create();
    $token = issueInvitation($organization, 'lapsed@example.com');

    throughTheAuditedDoor(fn () => $this->get(route('invitations.show', ['token' => $token])));

    $this->travel(8)->days();

    throughTheAuditedDoor(fn () => $this->post(route('register.store'), [
        'name' => 'Lapsed',
        'email' => 'lapsed@example.com',
        'password' => 'password-that-is-long',
        'password_confirmation' => 'password-that-is-long',
    ])->assertRedirect());

    expect(User::query()->where('email', 'lapsed@example.com')->exists())->toBeTrue()
        ->and(Inertia::getFlashed()['toast'] ?? null)->toMatchArray(['type' => 'warning']);
});
