<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\ConsumePendingInvitation;
use App\Actions\CreatePersonalOrganization;
use App\Enums\InvitationStatus;
use App\Enums\MembershipRole;
use App\Http\Middleware\ResolveTenantContext;
use App\Mail\OrganizationInvitation;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Tests\Support\TenantQueryGuard;

describe('the members screen', function (): void {
    it('redirects a guest to sign in', function (): void {
        $this->get(route('organizations.members.index'))->assertRedirect(route('login'));
    });

    it('shows members and pending invitations to a member', function (): void {
        [$organization, $owner] = organizationOwnedBySomeone();
        issueInvitation($organization, 'pending@example.com', $owner);

        $this->actingAs($owner)
            ->get(route('organizations.members.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('organizations/Members')
                ->has('members.data', 1)
                ->has('invitations.data', 1)
                ->where('invitations.data.0.email', 'pending@example.com')
                ->where('canInvite', true));
    });

    it('does not offer the invite form to a plain member', function (): void {
        [$organization, $owner] = organizationOwnedBySomeone();
        $member = User::factory()->create();

        resolve(TenantContext::class)->runFor(
            $organization,
            fn (): Membership => resolve(AddOrganizationMember::class)->handle($organization, $member),
        );

        $this->actingAs($member)
            ->get(route('organizations.members.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canInvite', false));
    });

    it('never lists another organization invitations', function (): void {
        [$ours, $owner] = organizationOwnedBySomeone();
        [$theirs] = organizationOwnedBySomeone();

        issueInvitation($ours, 'ours@example.com', $owner);
        issueInvitation($theirs, 'theirs@example.com');

        $this->actingAs($owner)
            ->get(route('organizations.members.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('invitations.data', 1)
                ->where('invitations.data.0.email', 'ours@example.com'));
    });
});

describe('issuing an invitation', function (): void {
    it('creates the invitation and queues its email', function (): void {
        Mail::fake();

        [$organization, $owner] = organizationOwnedBySomeone();

        $this->actingAs($owner)
            ->post(route('organizations.invitations.store'), [
                'email' => 'new@example.com',
                'role' => MembershipRole::Member->value,
            ])
            ->assertRedirect();

        $invitation = resolve(TenantContext::class)->runFor(
            $organization,
            fn (): Invitation => Invitation::query()->sole(),
        );

        expect($invitation->email)->toBe('new@example.com')
            ->and($invitation->status)->toBe(InvitationStatus::Pending)
            ->and($invitation->invited_by_user_id)->toBe($owner->id);

        Mail::assertQueued(
            OrganizationInvitation::class,
            fn (OrganizationInvitation $mail): bool => $mail->hasTo('new@example.com'),
        );
    });

    it('refuses a member who does not manage the organization', function (): void {
        Mail::fake();

        [$organization, $owner] = organizationOwnedBySomeone();
        $member = User::factory()->create();

        resolve(TenantContext::class)->runFor(
            $organization,
            fn (): Membership => resolve(AddOrganizationMember::class)->handle($organization, $member),
        );

        $this->actingAs($member)
            ->post(route('organizations.invitations.store'), [
                'email' => 'nope@example.com',
                'role' => MembershipRole::Member->value,
            ])
            ->assertForbidden();

        Mail::assertNothingQueued();
    });

    it('rejects an address that is not an email', function (): void {
        [, $owner] = organizationOwnedBySomeone();

        $this->actingAs($owner)
            ->post(route('organizations.invitations.store'), [
                'email' => 'not-an-address',
                'role' => MembershipRole::Member->value,
            ])
            ->assertSessionHasErrors('email');
    });

    it('rejects a required field that is missing', function (): void {
        [, $owner] = organizationOwnedBySomeone();

        $this->actingAs($owner)
            ->post(route('organizations.invitations.store'), [])
            ->assertSessionHasErrors(['email', 'role']);
    });

    it('surfaces a refusal as a form error rather than an exception', function (): void {
        Mail::fake();

        [$organization, $owner] = organizationOwnedBySomeone();
        issueInvitation($organization, 'twice@example.com', $owner);

        $this->actingAs($owner)
            ->post(route('organizations.invitations.store'), [
                'email' => 'twice@example.com',
                'role' => MembershipRole::Member->value,
            ])
            ->assertSessionHasErrors('email');
    });
});

describe('withdrawing an invitation', function (): void {
    it('revokes it and stops the token working', function (): void {
        [$organization, $owner] = organizationOwnedBySomeone();
        $token = issueInvitation($organization, 'bye@example.com', $owner);

        $invitation = resolve(TenantContext::class)->runFor(
            $organization,
            fn (): Invitation => Invitation::query()->sole(),
        );

        $this->actingAs($owner)
            ->delete(route('organizations.invitations.destroy', $invitation))
            ->assertRedirect();

        expect($invitation->fresh()->status)->toBe(InvitationStatus::Revoked);

        throughTheAuditedDoor(fn () => $this->get(route('invitations.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('refusal', fn (?string $refusal): bool => is_string($refusal))));
    });

    it('returns 404 rather than 403 for another organization invitation', function (): void {
        [, $owner] = organizationOwnedBySomeone();
        [$theirs] = organizationOwnedBySomeone();

        issueInvitation($theirs, 'theirs@example.com');

        $theirInvitation = TenantQueryGuard::allowUnscoped(
            fn (): Invitation => Invitation::query()->withoutGlobalScopes()->sole(),
        );

        // A 403 would confirm the row exists; one organization must not learn that
        // another invited anybody.
        $this->actingAs($owner)
            ->delete(route('organizations.invitations.destroy', $theirInvitation))
            ->assertNotFound();
    });
});

describe('refusing a closed invitation', function (): void {
    it('reports that an accepted invitation can no longer be withdrawn', function (): void {
        [$organization, $owner] = organizationOwnedBySomeone();
        $token = issueInvitation($organization, 'joined@example.com', $owner);
        $invitee = User::factory()->create(['email' => 'joined@example.com']);
        throughTheAuditedDoor(fn () => $this->actingAs($invitee)->post(route('invitations.accept', ['token' => $token])));

        $invitation = resolve(TenantContext::class)->runFor($organization, fn (): Invitation => Invitation::query()->sole());

        $this->actingAs($owner)
            ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
            ->delete(route('organizations.invitations.destroy', $invitation))
            ->assertRedirect();

        expect(Inertia::getFlashed()['toast'] ?? null)->toBe(['type' => 'error', 'message' => 'This invitation has already been accepted.'])
            ->and($invitation->fresh()->status)->toBe(InvitationStatus::Accepted);
    });

    it('reports that an accepted invitation can no longer be sent again', function (): void {
        Mail::fake();
        [$organization, $owner] = organizationOwnedBySomeone();
        $token = issueInvitation($organization, 'joined@example.com', $owner);
        $invitee = User::factory()->create(['email' => 'joined@example.com']);
        throughTheAuditedDoor(fn () => $this->actingAs($invitee)->post(route('invitations.accept', ['token' => $token])));

        $invitation = resolve(TenantContext::class)->runFor($organization, fn (): Invitation => Invitation::query()->sole());

        $this->actingAs($owner)
            ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
            ->post(route('organizations.invitations.deliveries.store', $invitation))
            ->assertRedirect();

        expect(Inertia::getFlashed()['toast'] ?? null)->toBe(['type' => 'error', 'message' => 'This invitation has already been accepted.']);
        Mail::assertNothingQueued();
    });
});

describe('the acceptance screen', function (): void {
    it('names the organization to a stranger and parks the token', function (): void {
        [$organization, $owner] = organizationOwnedBySomeone();
        $token = issueInvitation($organization, 'stranger@example.com', $owner);

        throughTheAuditedDoor(fn () => $this->get(route('invitations.show', ['token' => $token]))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertInertia(fn ($page) => $page
                ->component('invitations/Show')
                ->where('organization', 'Acme')
                ->where('email', 'stranger@example.com')
                ->where('authenticated', false)));

        expect(session(ConsumePendingInvitation::SESSION_KEY))->toEqual($token);
    });

    it('returns 404 for a token that names nothing', function (): void {
        throughTheAuditedDoor(fn () => $this->get(route('invitations.show', ['token' => 'nonsense']))->assertNotFound());
    });

    it('tells a signed-in stranger which account the invitation is for', function (): void {
        [$organization, $owner] = organizationOwnedBySomeone();
        $token = issueInvitation($organization, 'intended@example.com', $owner);

        $someoneElse = User::factory()->create(['email' => 'other@example.com']);

        throughTheAuditedDoor(fn () => $this->actingAs($someoneElse)
            ->get(route('invitations.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('authenticated', true)
                ->where('refusal', fn (?string $refusal): bool => is_string($refusal)
                    && str_contains($refusal, 'intended@example.com'))));
    });
});

describe('accepting', function (): void {
    it('adds the invitee and switches them into the organization', function (): void {
        [$organization, $owner] = organizationOwnedBySomeone();
        $token = issueInvitation($organization, 'joiner@example.com', $owner);

        $invitee = User::factory()->create(['email' => 'joiner@example.com']);

        throughTheAuditedDoor(fn () => $this->actingAs($invitee)
            ->post(route('invitations.accept', ['token' => $token]))
            ->assertRedirect(route('dashboard')));

        $membership = resolve(TenantContext::class)->runFor(
            $organization,
            fn (): ?Membership => Membership::query()->where('user_id', $invitee->id)->first(),
        );

        expect($membership)->not->toBeNull()
            ->and(session(ResolveTenantContext::SESSION_KEY))
            ->toBe($organization->id);
    });

    it('refuses a guest', function (): void {
        [$organization, $owner] = organizationOwnedBySomeone();
        $token = issueInvitation($organization, 'guest@example.com', $owner);

        throughTheAuditedDoor(fn () => $this->post(route('invitations.accept', ['token' => $token]))
            ->assertRedirect(route('login')));
    });

    it('returns a form error rather than a 500 when the invitation has lapsed', function (): void {
        [$organization, $owner] = organizationOwnedBySomeone();
        $token = issueInvitation($organization, 'late@example.com', $owner);
        $invitee = User::factory()->create(['email' => 'late@example.com']);

        $this->travel(8)->days();

        throughTheAuditedDoor(fn () => $this->actingAs($invitee)
            ->post(route('invitations.accept', ['token' => $token]))
            ->assertSessionHasErrors('invitation'));
    });
});

describe('declining', function (): void {
    it('lets the addressee decline', function (): void {
        [$organization, $owner] = organizationOwnedBySomeone();
        $token = issueInvitation($organization, 'nothanks@example.com', $owner);
        $invitee = User::factory()->create(['email' => 'nothanks@example.com']);

        throughTheAuditedDoor(fn () => $this->actingAs($invitee)
            ->delete(route('invitations.decline', ['token' => $token]))
            ->assertRedirect(route('home')));

        $invitation = resolve(TenantContext::class)->runFor(
            $organization,
            fn (): Invitation => Invitation::query()->sole(),
        );

        expect($invitation->status)->toBe(InvitationStatus::Declined);
    });

    it('lets an addressee who already has an organization decline', function (): void {
        [$organization, $owner] = organizationOwnedBySomeone();
        $token = issueInvitation($organization, 'registered@example.com', $owner);
        $invitee = User::factory()->create(['email' => 'registered@example.com']);
        resolve(CreatePersonalOrganization::class)->handle($invitee);

        throughTheAuditedDoor(fn () => $this->actingAs($invitee)
            ->delete(route('invitations.decline', ['token' => $token]))
            ->assertRedirect(route('home')));

        $invitation = resolve(TenantContext::class)->runFor(
            $organization,
            fn (): Invitation => Invitation::query()->sole(),
        );

        expect($invitation->status)->toBe(InvitationStatus::Declined);
    });

    it('returns a form error rather than a 500 when the invitation was already accepted', function (): void {
        [$organization, $owner] = organizationOwnedBySomeone();
        $token = issueInvitation($organization, 'twice@example.com', $owner);
        $invitee = User::factory()->create(['email' => 'twice@example.com']);

        throughTheAuditedDoor(fn () => $this->actingAs($invitee)
            ->post(route('invitations.accept', ['token' => $token])));

        throughTheAuditedDoor(fn () => $this->actingAs($invitee)
            ->delete(route('invitations.decline', ['token' => $token]))
            ->assertSessionHasErrors(['invitation' => 'This invitation has already been accepted.']));

        $invitation = resolve(TenantContext::class)->runFor(
            $organization,
            fn (): Invitation => Invitation::query()->sole(),
        );

        expect($invitation->status)->toBe(InvitationStatus::Accepted);
    });

    it('refuses somebody who merely has the link', function (): void {
        [$organization, $owner] = organizationOwnedBySomeone();
        $token = issueInvitation($organization, 'mine@example.com', $owner);

        $passerby = User::factory()->create(['email' => 'passerby@example.com']);

        throughTheAuditedDoor(fn () => $this->actingAs($passerby)
            ->delete(route('invitations.decline', ['token' => $token]))
            ->assertForbidden());
    });
});

it('pages the member list once it outgrows one page', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();

    resolve(TenantContext::class)->runFor($organization, function () use ($organization): void {
        for ($i = 0; $i < 30; $i++) {
            resolve(AddOrganizationMember::class)->handle($organization, User::factory()->create());
        }
    });

    $this->actingAs($owner)
        ->get(route('organizations.members.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('members.total', 31)
            ->has('members.data', 25)
            // Fewer than four links means a single page, and the component hides
            // itself, leaving later members unreachable.
            ->has('members.links', 4));

    $this->actingAs($owner)
        ->get(route('organizations.members.index', ['members' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('members.data', 6));
});

it('sends a role label to display, and the stored value only where a control needs it', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    issueInvitation($organization, 'labelled@example.com', $owner);

    // A member row carries a label and the value the role select binds to; an
    // invitation has no select, so only the label.
    $this->actingAs($owner)
        ->get(route('organizations.members.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('members.data.0.roleLabel', 'Admin')
            ->where('members.data.0.role', 'admin')
            ->where('invitations.data.0.role', 'Member'));
});

it('sends dates to the client already formatted', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $token = issueInvitation($organization, 'dated@example.com', $owner);

    // Formatting client-side lets Intl pick a different locale than SSR, which Vue
    // reports as a hydration mismatch.
    $this->actingAs($owner)
        ->get(route('organizations.members.index'))
        ->assertInertia(fn ($page) => $page
            ->where('invitations.data.0.expiresAt', now()->addDays(7)->toFormattedDateString()));

    throughTheAuditedDoor(fn () => $this->get(route('invitations.show', ['token' => $token]))
        ->assertInertia(fn ($page) => $page
            ->where('expiresAt', now()->addDays(7)->toFormattedDateString())));
});
