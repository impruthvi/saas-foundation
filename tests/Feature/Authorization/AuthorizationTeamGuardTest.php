<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Spatie\Permission\PermissionRegistrar;

it('fails a test whose permissions team was set by something other than the tenant', function (): void {
    resolve(TenantContext::class)->set(Organization::factory()->create());

    resolve(PermissionRegistrar::class)->setPermissionsTeamId(999_999);

    Project::query()->count();
})->throws(RuntimeException::class, 'Authorization would answer for organization [999999]');

it('fails a test that forgets the tenant without the team following', function (): void {
    $organization = Organization::factory()->create();
    $tenant = resolve(TenantContext::class);
    $tenant->set($organization);

    $tenant->forget();

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);

    Organization::query()->count();
})->throws(RuntimeException::class, 'while the resolved tenant is [none]');
