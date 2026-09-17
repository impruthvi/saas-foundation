<?php

declare(strict_types=1);

use App\Exceptions\CrossTenantAccess;
use App\Exceptions\TenantContextMissing;
use App\Models\Organization;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Tests\Support\TenantQueryGuard;

/*
|--------------------------------------------------------------------------
| The boundary, as the real models wear it
|--------------------------------------------------------------------------
|
| tests/Feature/Tenancy/TenantBoundaryTest.php asserts the rule against a
| fixture. These assert that Project and Membership actually wear it, including
| the two paths a global scope alone does not cover: inserts, which never reach
| a scope, and rows restored with scopes switched off.
|
*/

it('stamps a new row with the resolved organization', function (): void {
    $organization = Organization::factory()->create();

    $project = resolve(TenantContext::class)->runForId(
        $organization->id,
        fn (): Project => Project::query()->create(['name' => 'First project']),
    );

    expect($project->organization_id)->toBe($organization->id);
});

it('refuses to create a tenant-owned row with no organization resolved', function (): void {
    Project::query()->create(['name' => 'Nobody owns this']);
})->throws(TenantContextMissing::class);

it('keeps one organization from reading another', function (): void {
    [$first, $second] = [Organization::factory()->create(), Organization::factory()->create()];

    Project::factory()->for($first)->create(['name' => 'theirs']);
    Project::factory()->for($second)->create(['name' => 'ours']);

    $tenant = resolve(TenantContext::class);

    $names = $tenant->runForId($second->id, fn (): array => Project::query()->pluck('name')->all());

    expect($names)->toBe(['ours']);
});

it('raises when a row arrives from another organization with the scope bypassed', function (): void {
    [$first, $second] = [Organization::factory()->create(), Organization::factory()->create()];

    $theirs = Project::factory()->for($first)->create(['name' => 'theirs']);

    // The shape of a queued job restoring a serialized model: Laravel's
    // newQueryForRestoration() calls newQueryWithoutScopes(), so the scope is
    // off and only the retrieved guard is left to notice (D24).
    resolve(TenantContext::class)->runForId($second->id, function () use ($theirs): void {
        TenantQueryGuard::allowUnscoped(
            fn () => Project::query()->withoutTenantScope()->whereKey($theirs->id)->first()
        );
    });
})->throws(CrossTenantAccess::class);

it('refuses to write through an instance that belongs to another organization', function (): void {
    [$first, $second] = [Organization::factory()->create(), Organization::factory()->create()];

    $theirs = Project::factory()->for($first)->create(['name' => 'theirs']);

    // Loaded with no tenant resolved, the way a console command or a stale
    // payload would hand one over.
    $stale = TenantQueryGuard::allowUnscoped(
        fn (): ?Project => Project::query()->withoutTenantScope()->find($theirs->id)
    );

    try {
        resolve(TenantContext::class)->runForId($second->id, function () use ($stale): void {
            $stale->forceFill(['name' => 'renamed'])->save();
        });

        test()->fail("Writing another organization's row should have been refused.");
    } catch (CrossTenantAccess) {
        $reloaded = TenantQueryGuard::allowUnscoped(
            fn (): ?Project => Project::query()->withoutTenantScope()->find($theirs->id)
        );

        expect($reloaded?->name)->toBe('theirs');
    }
});
