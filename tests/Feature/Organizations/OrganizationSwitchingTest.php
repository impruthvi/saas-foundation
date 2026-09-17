<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\CreateOrganization;
use App\Actions\CreatePersonalOrganization;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\User;
use App\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Which organization a request is acting for
|--------------------------------------------------------------------------
|
| The current organization lives in the session and moves through an explicit
| switch (D26). Membership decides what the session is allowed to name, so
| these assert the refusals as hard as the happy path.
|
*/

it('resolves the personal organization and hides the switcher for a solo user', function (): void {
    $user = User::factory()->create();
    $organization = resolve(CreatePersonalOrganization::class)->handle($user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('organization.id', $organization->id)
            ->where('organization.personal', true)
            ->has('organizations', 1));

    expect(session(ResolveTenantContext::SESSION_KEY))->toBe($organization->id);
});

it('switches to another organization the user is a member of', function (): void {
    $user = User::factory()->create();
    $personal = resolve(CreatePersonalOrganization::class)->handle($user);
    $shared = resolve(CreateOrganization::class)->handle(User::factory()->create(), 'Acme');
    resolve(AddOrganizationMember::class)->handle($shared, $user);

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('organizations.switch', $shared))
        ->assertRedirect(route('dashboard'));

    expect(session(ResolveTenantContext::SESSION_KEY))->toBe($shared->id);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('organization.id', $shared->id)
            ->where('organization.name', 'Acme')
            ->has('organizations', 2));

    expect($personal->id)->not->toBe($shared->id);
});

it('refuses to switch to an organization the user has no membership of', function (): void {
    $user = User::factory()->create();
    resolve(CreatePersonalOrganization::class)->handle($user);

    $theirs = resolve(CreateOrganization::class)->handle(User::factory()->create(), 'Somebody else');

    $this->actingAs($user)
        ->post(route('organizations.switch', $theirs))
        ->assertForbidden();

    expect(session(ResolveTenantContext::SESSION_KEY))->not->toBe($theirs->id);
});

it('refuses to switch to an organization that is not usable', function (): void {
    $user = User::factory()->create();
    resolve(CreatePersonalOrganization::class)->handle($user);

    $suspended = resolve(CreateOrganization::class)->handle($user, 'Suspended');
    $suspended->forceFill(['status' => 'suspended'])->save();

    $this->actingAs($user)
        ->post(route('organizations.switch', $suspended))
        ->assertForbidden();
});

it('falls back when the session names an organization the user has left', function (): void {
    $user = User::factory()->create();
    $personal = resolve(CreatePersonalOrganization::class)->handle($user);
    $shared = resolve(CreateOrganization::class)->handle(User::factory()->create(), 'Acme');
    $membership = resolve(AddOrganizationMember::class)->handle($shared, $user);

    $this->actingAs($user)->post(route('organizations.switch', $shared));

    resolve(TenantContext::class)->runFor($shared, function () use ($membership): void {
        $membership->delete();
    });

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('organization.id', $personal->id)
            ->has('organizations', 1));
});

it('resolves no organization for a guest', function (): void {
    $this->get(route('home'))->assertOk();

    expect(resolve(TenantContext::class)->hasTenant())->toBeFalse();
});

it('leaves a user with no membership acting for no organization', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('organization', null)
            ->has('organizations', 0));
});
