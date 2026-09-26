<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\RecordResolvedTenant;

beforeEach(function (): void {
    config()->set('queue.default', 'database');
});

it('carries the resolved organization into a job through the payload', function (): void {
    $organization = Organization::factory()->create();
    $tenant = resolve(TenantContext::class);

    $tenant->runFor($organization, function (): void {
        Project::query()->create(['name' => 'Ours']);

        dispatch(new RecordResolvedTenant('carried'));
    });

    $payload = (string) DB::table('jobs')->value('payload');

    expect($payload)->toContain('illuminate:log:context')
        ->and($payload)->toContain(TenantContext::KEY);

    // A worker process has no ambient tenant, only what the payload carries.
    $tenant->forget();

    $this->artisan('queue:work --once')->assertSuccessful();

    expect(RecordResolvedTenant::recorded('carried'))
        ->toBe(['organization_id' => $organization->id, 'visible_projects' => 1]);
});

it('leaves nothing resolved for the next job on the same worker', function (): void {
    $organization = Organization::factory()->create();
    $tenant = resolve(TenantContext::class);

    // A statement, not a returned expression: dispatch() returns a PendingDispatch that
    // pushes on destruction, which would be after runFor() restored the tenant.
    $tenant->runFor($organization, function (): void {
        dispatch(new RecordResolvedTenant('tenanted'));
    });

    dispatch(new RecordResolvedTenant('untenanted'));

    $tenant->forget();

    $this->artisan('queue:work --once')->assertSuccessful();
    $this->artisan('queue:work --once')->assertSuccessful();

    expect(RecordResolvedTenant::recorded('tenanted'))
        ->toBe(['organization_id' => $organization->id, 'visible_projects' => 0])
        ->and(RecordResolvedTenant::recorded('untenanted'))
        ->toBe(['organization_id' => null, 'visible_projects' => null]);
});

it('keeps one organization out of another organization rows in background work', function (): void {
    [$first, $second] = [Organization::factory()->create(), Organization::factory()->create()];
    $tenant = resolve(TenantContext::class);

    $tenant->runFor($first, function (): void {
        Project::query()->create(['name' => 'Theirs']);
    });

    $tenant->runFor($second, function (): void {
        Project::query()->create(['name' => 'Ours']);
        Project::query()->create(['name' => 'Also ours']);

        dispatch(new RecordResolvedTenant('scoped'));
    });

    $tenant->forget();

    $this->artisan('queue:work --once')->assertSuccessful();

    expect(RecordResolvedTenant::recorded('scoped'))
        ->toBe(['organization_id' => $second->id, 'visible_projects' => 2]);
});
