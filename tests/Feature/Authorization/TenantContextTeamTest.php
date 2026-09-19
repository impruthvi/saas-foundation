<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Tenancy\TenantContext;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fixtures\RecordAuthorizationTeam;

/**
 * The organization every role assignment would currently be read against.
 */
function teamInForce(): int|string|null
{
    return resolve(PermissionRegistrar::class)->getPermissionsTeamId();
}

it('resolves the team alongside the tenant', function (): void {
    $organization = Organization::factory()->create();

    resolve(TenantContext::class)->set($organization);

    expect(teamInForce())->toBe($organization->id);
});

it('leaves no team in force once the tenant is forgotten', function (): void {
    $tenant = resolve(TenantContext::class);
    $tenant->set(Organization::factory()->create());

    $tenant->forget();

    expect(teamInForce())->toBeNull();
});

it('restores the surrounding team after acting for another organization', function (): void {
    $home = Organization::factory()->create();
    $other = Organization::factory()->create();
    $tenant = resolve(TenantContext::class);
    $tenant->set($home);

    $seen = $tenant->runFor($other, fn (): int|string|null => teamInForce());

    expect($seen)->toBe($other->id)
        ->and(teamInForce())->toBe($home->id);
});

it('restores no team at all when there was none to begin with', function (): void {
    $tenant = resolve(TenantContext::class);
    $tenant->forget();

    $tenant->runFor(Organization::factory()->create(), fn (): null => null);

    expect(teamInForce())->toBeNull();
});

it('puts no team in force for work that is deliberately cross-tenant', function (): void {
    $tenant = resolve(TenantContext::class);
    $organization = Organization::factory()->create();
    $tenant->set($organization);

    $seen = $tenant->runWithoutTenant(fn (): int|string|null => teamInForce());

    expect($seen)->toBeNull()
        ->and(teamInForce())->toBe($organization->id);
});

it('leaves no team in force for the next job on the same worker', function (): void {
    config()->set('queue.default', 'database');

    $organization = Organization::factory()->create();
    $tenant = resolve(TenantContext::class);

    // A statement rather than a returned expression: dispatch() hands back a
    // PendingDispatch that pushes on destruction, so returning it out of
    // runFor() would push after the tenant had been restored.
    $tenant->runFor($organization, function (): void {
        dispatch(new RecordAuthorizationTeam('during'));
    });

    // Pushed with no tenant resolved, so its payload carries no context — and
    // pushed now rather than after the first job is worked, which would capture
    // the tenant that job resolved and pass for the wrong reason.
    dispatch(new RecordAuthorizationTeam('after'));

    // A worker booting fresh has no ambient tenant, only what a payload carries.
    $tenant->forget();

    $this->artisan('queue:work --once')->assertSuccessful();
    $this->artisan('queue:work --once')->assertSuccessful();

    expect(RecordAuthorizationTeam::recorded('during'))->toBe($organization->id)
        ->and(RecordAuthorizationTeam::hasRun('after'))->toBeTrue()
        ->and(RecordAuthorizationTeam::recorded('after'))->toBeNull();
});
