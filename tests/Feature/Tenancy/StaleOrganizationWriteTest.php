<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\CreateOrganization;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

/**
 * The owner of two organizations, with the session already moved to the second: a page
 * rendered for the first is still open in another tab.
 *
 * @return array{0: User, 1: Organization, 2: Organization}
 */
function ownerWhoSwitchedAway(): array
{
    [$shown, $owner] = organizationOwnedBySomeone('Shown');
    $current = resolve(CreateOrganization::class)->handle($owner, 'Current');

    test()->actingAs($owner)->withSession([ResolveTenantContext::SESSION_KEY => $current->id]);

    return [$owner, $shown, $current];
}

function invitationsIn(Organization $organization): int
{
    return resolve(TenantContext::class)->runFor($organization, fn (): int => Invitation::query()->count());
}

it('refuses an invitation sent from a page for the organization the session left', function (): void {
    Mail::fake();
    [, $shown, $current] = ownerWhoSwitchedAway();

    $this->post(route('organizations.invitations.store'), [
        'organization' => $shown->slug,
        'email' => 'stale@example.com',
        'role' => 'member',
    ])->assertConflict()->assertSeeText('This organization changed elsewhere.');

    expect(invitationsIn($shown))->toBe(0)
        ->and(invitationsIn($current))->toBe(0);
});

it('refuses a removal from a stale page with a conflict, not a missing record', function (): void {
    [, $shown] = ownerWhoSwitchedAway();
    $membership = resolve(TenantContext::class)->runFor(
        $shown,
        fn (): Membership => resolve(AddOrganizationMember::class)->handle($shown, User::factory()->create()),
    );

    $this->delete(route('organizations.members.destroy', $membership), ['organization' => $shown->slug])
        ->assertConflict();

    expect(resolve(TenantContext::class)->runFor($shown, fn (): bool => Membership::query()->whereKey($membership->id)->exists()))->toBeTrue();
});

it('sends an Inertia visit back with the reason instead of an error page', function (): void {
    [, $shown] = ownerWhoSwitchedAway();

    $this->withHeaders(['X-Inertia' => 'true'])
        ->from(route('organizations.members.index'))
        ->post(route('organizations.invitations.store'), [
            'organization' => $shown->slug,
            'email' => 'stale@example.com',
            'role' => 'member',
        ])
        ->assertRedirect(route('organizations.members.index'));

    expect(Inertia::getFlashed()['toast'] ?? null)->toBe([
        'type' => 'error',
        'message' => 'This organization changed elsewhere. Refresh the page and try again.',
    ]);
});

it('requires every tenant-scoped write to name its organization', function (Closure $request): void {
    Mail::fake();
    [$organization, $owner] = organizationOwnedBySomeone();
    $membership = resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Membership => resolve(AddOrganizationMember::class)->handle($organization, User::factory()->create()),
    );
    $invitation = findInvitation(issueInvitation($organization, 'named@example.com', $owner));

    $this->actingAs($owner)->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);

    $request($this, $membership, $invitation)->assertSessionHasErrors('organization');
})->with([
    'inviting' => [fn ($test) => $test->post(route('organizations.invitations.store'), ['email' => 'new@example.com', 'role' => 'member'])],
    'resending' => [fn ($test, $membership, $invitation) => $test->post(route('organizations.invitations.deliveries.store', $invitation))],
    'withdrawing' => [fn ($test, $membership, $invitation) => $test->delete(route('organizations.invitations.destroy', $invitation))],
    'changing a rank' => [fn ($test, $membership) => $test->patch(route('organizations.members.update', $membership), ['role' => 'admin'])],
    'removing a member' => [fn ($test, $membership) => $test->delete(route('organizations.members.destroy', $membership))],
]);
