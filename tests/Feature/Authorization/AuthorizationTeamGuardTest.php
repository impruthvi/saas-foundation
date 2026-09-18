<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The guard that watches the guard
|--------------------------------------------------------------------------
|
| `AuthorizationTeamGuard` is installed for every test in the suite, so the
| only way to know it can fail is to make it fail on purpose. These are the
| two drifts it exists to catch, written the way a defect would produce them:
| a team set by something other than TenantContext, and a tenant that is gone
| while the team it resolved is not.
|
*/

it('fails a test whose permissions team was set by something other than the tenant', function (): void {
    resolve(TenantContext::class)->set(Organization::factory()->create());

    resolve(PermissionRegistrar::class)->setPermissionsTeamId(999_999);

    Project::query()->count();
})->throws(RuntimeException::class, 'Authorization would answer for organization [999999]');

it('fails a test that forgets the tenant without the team following', function (): void {
    $organization = Organization::factory()->create();
    $tenant = resolve(TenantContext::class);
    $tenant->set($organization);

    // What forget() looked like before D29: the tenant goes, the team stays.
    $tenant->forget();

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);

    Organization::query()->count();
})->throws(RuntimeException::class, 'while the resolved tenant is [none]');
