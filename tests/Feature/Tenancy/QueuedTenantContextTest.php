<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\RecordResolvedTenant;

/*
|--------------------------------------------------------------------------
| The tenant across a real queue roundtrip
|--------------------------------------------------------------------------
|
| M1's definition-of-done: "a job resolves the correct organization across a
| real queue roundtrip, and the next job on that worker inherits nothing."
|
| These deliberately do not use the sync driver or Queue::fake(). Sync runs the
| job inline with the tenant still ambiently resolved, so the same assertions
| would pass with every line of propagation deleted — a test that cannot fail
| is worse than no test. The payload is written to the jobs table, the
| singleton is dropped the way a fresh worker process would not have it, and
| the job is then worked out of the database.
|
|   dispatch inside runFor ──► jobs.payload carries illuminate:log:context
|            │
|            ▼
|   forget()  (what a worker booting fresh looks like)
|            │
|            ▼
|   queue:work --once ──► JobProcessing ──► Context::hydrate ──► tenant resolved
|
*/

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

    // A statement, not a returned expression: dispatch() hands back a
    // PendingDispatch that pushes the job when it is destructed, so returning it
    // out of runFor() would push it after the tenant had already been restored,
    // and the payload would carry the wrong context or none at all.
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
