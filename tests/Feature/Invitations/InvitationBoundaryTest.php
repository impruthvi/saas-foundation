<?php

declare(strict_types=1);

use App\Actions\AcceptOrganizationInvitation;
use App\Actions\AddOrganizationMember;
use App\Actions\DeclineOrganizationInvitation;
use App\Actions\ResendOrganizationInvitation;
use App\Actions\RevokeOrganizationInvitation;
use App\Enums\AuditAction;
use App\Enums\InvitationStatus;
use App\Enums\MembershipRole;
use App\Enums\OrganizationStatus;
use App\Exceptions\CrossTenantAccess;
use App\Exceptions\Invitations\AlreadyInvited;
use App\Exceptions\Invitations\AlreadyMember;
use App\Exceptions\Invitations\InvitationAddressedToAnother;
use App\Exceptions\Invitations\InvitationAlreadyAccepted;
use App\Exceptions\Invitations\InvitationDeclined;
use App\Exceptions\Invitations\InvitationExpired;
use App\Exceptions\Invitations\InvitationRevoked;
use App\Exceptions\Invitations\OrganizationNotAcceptingMembers;
use App\Models\AuditEvent;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;

it('mints a token that resolves to the invitation and is never stored in the clear', function (): void {
    $organization = Organization::factory()->create();

    $token = issueInvitation($organization, 'new@example.com');

    $invitation = findInvitation($token);

    expect($invitation)->toBeInstanceOf(Invitation::class)
        ->and($invitation->email)->toBe('new@example.com')
        ->and($invitation->organization_id)->toBe($organization->id)
        ->and($invitation->token_hash)->not->toBe($token)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token));
});

it('resolves an invitation for a stranger who has no organization of their own', function (): void {
    $organization = Organization::factory()->create();
    $token = issueInvitation($organization, 'stranger@example.com');

    // A stranger has no tenant, so the token lookup must take the explicit cross-tenant
    // path.
    resolve(TenantContext::class)->forget();

    expect(findInvitation($token))
        ->toBeInstanceOf(Invitation::class);
});

it('accepts an invitation and makes the invitee a member of the inviting organization', function (): void {
    $organization = Organization::factory()->create();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $token = issueInvitation($organization, 'invitee@example.com');

    $invitation = findInvitation($token);
    $membership = resolve(AcceptOrganizationInvitation::class)->handle($invitation, $invitee);

    expect($membership->organization_id)->toBe($organization->id)
        ->and($membership->user_id)->toBe($invitee->id)
        ->and($membership->role)->toBe(MembershipRole::Member)
        ->and($invitation->fresh()->status)->toBe(InvitationStatus::Accepted)
        ->and($invitation->fresh()->accepted_by_user_id)->toBe($invitee->id);
});

it('rejects an invitation whose clock ran out', function (): void {
    $organization = Organization::factory()->create();
    $invitee = User::factory()->create(['email' => 'late@example.com']);
    $token = issueInvitation($organization, 'late@example.com');

    $this->travel(8)->days();

    $invitation = findInvitation($token);

    expect(fn () => resolve(AcceptOrganizationInvitation::class)->handle($invitation, $invitee))
        ->toThrow(InvitationExpired::class);
});

it('rejects an invitation that was revoked before it was accepted', function (): void {
    $organization = Organization::factory()->create();
    $invitee = User::factory()->create(['email' => 'withdrawn@example.com']);
    $token = issueInvitation($organization, 'withdrawn@example.com');

    $invitation = findInvitation($token);
    resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Invitation => resolve(RevokeOrganizationInvitation::class)->handle($invitation),
    );

    expect(fn () => resolve(AcceptOrganizationInvitation::class)->handle($invitation->fresh(), $invitee))
        ->toThrow(InvitationRevoked::class);
});

it('rejects an invitation the recipient already declined', function (): void {
    $organization = Organization::factory()->create();
    $invitee = User::factory()->create(['email' => 'nothanks@example.com']);
    $token = issueInvitation($organization, 'nothanks@example.com');

    $invitation = findInvitation($token);
    resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Invitation => resolve(DeclineOrganizationInvitation::class)->handle($invitation),
    );

    expect(fn () => resolve(AcceptOrganizationInvitation::class)->handle($invitation->fresh(), $invitee))
        ->toThrow(InvitationDeclined::class);
});

it('rejects acceptance by a user the invitation does not name', function (): void {
    $organization = Organization::factory()->create();
    $someoneElse = User::factory()->create(['email' => 'someone.else@example.com']);
    $token = issueInvitation($organization, 'intended@example.com');

    $invitation = findInvitation($token);

    expect(fn () => resolve(AcceptOrganizationInvitation::class)->handle($invitation, $someoneElse))
        ->toThrow(InvitationAddressedToAnother::class);
});

it('matches the addressee regardless of how the address is cased', function (): void {
    $organization = Organization::factory()->create();
    $invitee = User::factory()->create(['email' => 'mixed.case@example.com']);
    $token = issueInvitation($organization, 'Mixed.Case@Example.com');

    $invitation = findInvitation($token);

    expect(resolve(AcceptOrganizationInvitation::class)->handle($invitation, $invitee))
        ->toBeInstanceOf(Membership::class);
});

it('rejects a second invitation while one is still live', function (): void {
    $organization = Organization::factory()->create();
    issueInvitation($organization, 'pending@example.com');

    expect(fn (): string => issueInvitation($organization, 'pending@example.com'))
        ->toThrow(AlreadyInvited::class);
});

it('rejects inviting somebody who is already a member', function (): void {
    $organization = Organization::factory()->create();
    $member = User::factory()->create(['email' => 'inside@example.com']);

    resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Membership => resolve(AddOrganizationMember::class)->handle($organization, $member),
    );

    expect(fn (): string => issueInvitation($organization, 'inside@example.com'))
        ->toThrow(AlreadyMember::class);
});

it('rejects acceptance into an organization that is no longer usable', function (): void {
    $organization = Organization::factory()->create();
    $invitee = User::factory()->create(['email' => 'toolate@example.com']);
    $token = issueInvitation($organization, 'toolate@example.com');

    $organization->update(['status' => OrganizationStatus::Suspended]);

    $invitation = findInvitation($token);

    expect(fn () => resolve(AcceptOrganizationInvitation::class)->handle($invitation, $invitee))
        ->toThrow(OrganizationNotAcceptingMembers::class);
});

it('rejects a second acceptance of an invitation already taken', function (): void {
    $organization = Organization::factory()->create();
    $invitee = User::factory()->create(['email' => 'twice@example.com']);
    $token = issueInvitation($organization, 'twice@example.com');

    $invitation = findInvitation($token);
    resolve(AcceptOrganizationInvitation::class)->handle($invitation, $invitee);

    expect(fn () => resolve(AcceptOrganizationInvitation::class)->handle($invitation->fresh(), $invitee))
        ->toThrow(InvitationAlreadyAccepted::class);
});

it('lets a revoked address be invited again, on a new token', function (): void {
    $organization = Organization::factory()->create();
    $first = issueInvitation($organization, 'again@example.com');

    $invitation = findInvitation($first);
    resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Invitation => resolve(RevokeOrganizationInvitation::class)->handle($invitation),
    );

    $second = issueInvitation($organization, 'again@example.com');

    expect($second)->not->toBe($first)
        ->and(findInvitation($first))->toBeNull()
        ->and(findInvitation($second)->status)
        ->toBe(InvitationStatus::Pending);
});

it('retires the previous token when an invitation is resent', function (): void {
    $organization = Organization::factory()->create();
    $first = issueInvitation($organization, 'resend@example.com');

    $invitation = findInvitation($first);
    $second = resolve(TenantContext::class)->runFor(
        $organization,
        fn (): string => resolve(ResendOrganizationInvitation::class)->handle($invitation)['token'],
    );

    // A retired token resolves to nothing, rendered as no longer valid rather than
    // expired.
    expect(findInvitation($first))->toBeNull()
        ->and(findInvitation($second))
        ->toBeInstanceOf(Invitation::class);
});

it('returns nothing for a token that was never issued', function (): void {
    expect(findInvitation('not-a-real-token'))->toBeNull();
});

it('survives the membership already existing when the invitation is accepted', function (): void {
    $organization = Organization::factory()->create();
    $invitee = User::factory()->create(['email' => 'racer@example.com']);
    $token = issueInvitation($organization, 'racer@example.com');

    // The state the loser of a concurrent accept finds. The row lock normally prevents
    // it; the caught unique violation is the backup, reachable in one process only like
    // this.
    resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Membership => resolve(AddOrganizationMember::class)->handle($organization, $invitee),
    );

    expect(fn () => resolve(AcceptOrganizationInvitation::class)->handle(findInvitation($token), $invitee))
        ->toThrow(InvitationAlreadyAccepted::class);

    $count = resolve(TenantContext::class)->runFor(
        $organization,
        fn (): int => Membership::query()->where('user_id', $invitee->id)->count(),
    );

    expect($count)->toBe(1);
});

it('keeps one organization from seeing or revoking another organization invitation', function (): void {
    [$ours, $theirs] = [Organization::factory()->create(), Organization::factory()->create()];

    issueInvitation($ours, 'ours@example.com');
    $theirToken = issueInvitation($theirs, 'theirs@example.com');

    $visible = resolve(TenantContext::class)->runFor(
        $ours,
        fn (): array => Invitation::query()->pluck('email')->all(),
    );

    expect($visible)->toBe(['ours@example.com']);

    // Reaching another organization's real row from inside our tenant is what the
    // retrieved guard stops.
    $theirInvitation = findInvitation($theirToken);

    expect(fn () => resolve(TenantContext::class)->runFor(
        $ours,
        fn (): Invitation => resolve(RevokeOrganizationInvitation::class)->handle($theirInvitation),
    ))->toThrow(CrossTenantAccess::class);
});

describe('closing an invitation that is already closed', function (): void {
    it('refuses to decline an invitation that was revoked', function (): void {
        $organization = Organization::factory()->create();
        $invitation = findInvitation(issueInvitation($organization, 'late@example.com'));
        resolve(TenantContext::class)->runFor($organization, fn (): Invitation => resolve(RevokeOrganizationInvitation::class)->handle($invitation));

        expect(fn () => resolve(DeclineOrganizationInvitation::class)->handle($invitation))
            ->toThrow(InvitationRevoked::class)
            ->and($invitation->fresh()?->status)->toBe(InvitationStatus::Revoked);
    });

    it('refuses to revoke an invitation that was declined', function (): void {
        $organization = Organization::factory()->create();
        $invitation = findInvitation(issueInvitation($organization, 'nothanks@example.com'));
        resolve(DeclineOrganizationInvitation::class)->handle($invitation);

        expect(fn () => resolve(TenantContext::class)->runFor($organization, fn (): Invitation => resolve(RevokeOrganizationInvitation::class)->handle($invitation)))
            ->toThrow(InvitationDeclined::class);

        $invitation = resolve(TenantContext::class)->runFor($organization, fn (): ?Invitation => $invitation->fresh());

        expect($invitation?->status)->toBe(InvitationStatus::Declined)
            ->and($invitation?->revoked_at)->toBeNull();
    });

    it('revokes an invitation once, and audits it once', function (): void {
        $organization = Organization::factory()->create();
        $invitation = findInvitation(issueInvitation($organization, 'twice@example.com'));
        $revoke = fn (): Invitation => resolve(TenantContext::class)->runFor($organization, fn (): Invitation => resolve(RevokeOrganizationInvitation::class)->handle($invitation));
        $revoke();

        expect($revoke)->toThrow(InvitationRevoked::class);

        $revocations = resolve(TenantContext::class)->runFor(
            $organization,
            fn (): int => AuditEvent::query()->where('action', AuditAction::InvitationRevoked)->count(),
        );

        expect($revocations)->toBe(1);
    });

    it('refuses an acceptance that a revocation overtook', function (): void {
        $organization = Organization::factory()->create();
        $invitee = User::factory()->create(['email' => 'overtaken@example.com']);
        $token = issueInvitation($organization, 'overtaken@example.com');

        // Loaded while pending, as the accept screen does, then revoked before accepting.
        $stale = findInvitation($token);
        resolve(TenantContext::class)->runFor($organization, fn (): Invitation => resolve(RevokeOrganizationInvitation::class)->handle(findInvitation($token)));

        expect(fn () => resolve(AcceptOrganizationInvitation::class)->handle($stale, $invitee))
            ->toThrow(InvitationRevoked::class);

        $members = resolve(TenantContext::class)->runFor(
            $organization,
            fn (): int => Membership::query()->where('user_id', $invitee->id)->count(),
        );

        expect($members)->toBe(0);
    });
});
