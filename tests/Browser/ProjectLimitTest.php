<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveTenantContext;
use App\Models\Project;
use App\Tenancy\TenantContext;

it('creates a project and counts it against the plan', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);

    visit('/projects')
        ->assertSee('This organization has no projects yet.')
        ->assertSee('0 of 2 projects used')
        ->type('name', 'Launch checklist')
        ->press('Create project')
        ->assertSee('Launch checklist')
        ->assertSee('1 of 2 projects used')
        ->assertNoJavaScriptErrors();

    expect(resolve(TenantContext::class)->runFor(
        $organization,
        fn (): int => Project::query()->count(),
    ))->toBe(1);
});

it('prompts for an upgrade once the free allowance is spent', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);

    $page = visit('/projects');

    $page->type('name', 'First project')
        ->press('Create project')
        ->assertSee('1 of 2 projects used')
        ->assertDontSee('You have used every project on this plan')
        ->type('name', 'Second project')
        ->press('Create project')
        ->assertSee('2 of 2 projects used')
        ->assertSee('You have used every project on this plan')
        ->assertSee('Upgrade to Pro')
        ->assertNoJavaScriptErrors();

    expect(resolve(TenantContext::class)->runFor(
        $organization,
        fn (): int => Project::query()->count(),
    ))->toBe(2);
});
