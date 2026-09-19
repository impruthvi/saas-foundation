<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\CreateOrganization;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\RecordPermissionInJob;

/*
|--------------------------------------------------------------------------
| A role granted in organization A grants nothing in organization B
|--------------------------------------------------------------------------
|
| M3's definition of done, in one sentence, asserted four ways: directly,
| across an explicit switch inside one request, over HTTP, and across a real
| queue roundtrip. Each is a different way the team the package answers for can
| come loose from the tenant the request resolved.
|
*/
/**
 * @return array{0: User, 1: Organization, 2: Organization}
 */
function someoneRankedDifferentlyInTwoOrganizations(): array
{
    $person = User::factory()->create();

    $acme = resolve(CreateOrganization::class)->handle(User::factory()->create(), 'Acme');
    $other = resolve(CreateOrganization::class)->handle(User::factory()->create(), 'Other');

    resolve(AddOrganizationMember::class)->handle($acme, $person, MembershipRole::Admin);
    resolve(AddOrganizationMember::class)->handle($other, $person, MembershipRole::Member);

    return [$person, $acme, $other];
}

it('grants the role in the organization it was granted in, and not the other', function (): void {
    [$person, $acme, $other] = someoneRankedDifferentlyInTwoOrganizations();

    expect(mayWithin($person, $acme->id, Permission::InviteMembers))->toBeTrue()
        ->and(mayWithin($person, $other->id, Permission::InviteMembers))->toBeFalse()
        ->and(mayWithin($person, $other->id, Permission::ViewMembers))->toBeTrue();
});

it('answers differently on each side of a switch inside one request', function (): void {
    [$person, $acme, $other] = someoneRankedDifferentlyInTwoOrganizations();
    $tenant = resolve(TenantContext::class);

    $inAcme = $tenant->runFor($acme, fn (): bool => $person->can('create', Invitation::class));
    $inOther = $tenant->runFor($other, fn (): bool => $person->can('create', Invitation::class));
    $backInAcme = $tenant->runFor($acme, fn (): bool => $person->can('create', Invitation::class));

    expect([$inAcme, $inOther, $backInAcme])->toBe([true, false, true]);
});

it('keeps a permission granted directly to one organization out of the other', function (): void {
    $person = User::factory()->create();
    $acme = resolve(CreateOrganization::class)->handle(User::factory()->create(), 'Acme');
    $other = resolve(CreateOrganization::class)->handle(User::factory()->create(), 'Other');

    resolve(AddOrganizationMember::class)->handle($acme, $person);
    resolve(AddOrganizationMember::class)->handle($other, $person);

    resolve(TenantContext::class)->runFor($acme, fn () => $person->givePermissionTo(Permission::InviteMembers->value));

    expect(mayWithin($person, $acme->id, Permission::InviteMembers))->toBeTrue()
        ->and(mayWithin($person, $other->id, Permission::InviteMembers))->toBeFalse();
});

it('refuses over HTTP in the organization the session switched to', function (): void {
    // Delivery is faked: the mailable carries the invitation, and restoring a
    // tenant-owned model from a payload is a separate boundary with its own
    // tests. What is under test here is who the endpoint lets through.
    Mail::fake();

    [$person, $acme, $other] = someoneRankedDifferentlyInTwoOrganizations();

    $this->actingAs($person)
        ->withSession([ResolveTenantContext::SESSION_KEY => $acme->id])
        ->post(route('organizations.invitations.store'), [
            'email' => 'someone@example.com',
            'role' => MembershipRole::Member->value,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($person)
        ->withSession([ResolveTenantContext::SESSION_KEY => $other->id])
        ->post(route('organizations.invitations.store'), [
            'email' => 'someone@example.com',
            'role' => MembershipRole::Member->value,
        ])
        ->assertForbidden();
});

it('refuses over HTTP after the switch endpoint moves the session', function (): void {
    Mail::fake();

    [$person, $acme, $other] = someoneRankedDifferentlyInTwoOrganizations();

    $this->actingAs($person)
        ->withSession([ResolveTenantContext::SESSION_KEY => $acme->id])
        ->post(route('organizations.switch', $other))
        ->assertRedirect();

    $this->actingAs($person)
        ->post(route('organizations.invitations.store'), [
            'email' => 'someone@example.com',
            'role' => MembershipRole::Member->value,
        ])
        ->assertForbidden();
});

it('authorizes a queued job for the organization its payload names', function (): void {
    config()->set('queue.default', 'database');

    [$person, $acme, $other] = someoneRankedDifferentlyInTwoOrganizations();
    $tenant = resolve(TenantContext::class);

    $tenant->runFor($acme, function () use ($person): void {
        dispatch(new RecordPermissionInJob('acme', $person, Permission::InviteMembers->value));
    });

    $tenant->runFor($other, function () use ($person): void {
        dispatch(new RecordPermissionInJob('other', $person, Permission::InviteMembers->value));
    });

    $tenant->forget();

    $this->artisan('queue:work --once')->assertSuccessful();
    $this->artisan('queue:work --once')->assertSuccessful();

    expect(RecordPermissionInJob::recorded('acme'))->toBeTrue()
        ->and(RecordPermissionInJob::recorded('other'))->toBeFalse();
});
