<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveTenantContext;
use App\Models\Operator;
use App\Providers\Filament\AdminConsoleServiceProvider;

/*
 * Clicks every link the product's navigation offers. The sidebar's links are
 * compiled into the frontend, so only a browser can see a dead one. This runs
 * in the ordinary browser job and again with the admin console removed.
 */

function signedInMember(): void
{
    [$organization, $owner] = organizationOwnedBySomeone();

    test()->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);
}

it('opens every sidebar link', function (string $label, string $path): void {
    signedInMember();

    visit('/settings/profile')
        ->click($label)
        ->assertPathIs($path)
        ->assertDontSee('Not Found')
        ->assertDontSee('Server Error')
        ->assertNoJavaScriptErrors();
})->with([
    'dashboard' => ['Dashboard', '/dashboard'],
    'projects' => ['Projects', '/projects'],
    'members' => ['Members', '/organizations/members'],
    'billing' => ['Billing', '/organizations/billing'],
]);

it('opens settings from the user menu', function (): void {
    signedInMember();

    visit('/dashboard')
        ->click('[data-test="sidebar-menu-button"]')
        ->click('Settings')
        ->assertPathIs('/settings/profile')
        ->assertNoJavaScriptErrors();
});

it('shows a member no link to the admin console', function (): void {
    signedInMember();

    visit('/dashboard')
        ->assertDontSee('Admin console')
        ->assertNoJavaScriptErrors();
});

it('shows an operator a working link to the admin console', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    Operator::factory()->create(['user_id' => $owner->id]);
    $this->actingAs($owner)->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);

    visit('/dashboard')
        ->assertSee('Admin console')
        ->assertNoJavaScriptErrors();

    visit('/admin/organizations')
        ->assertSee('Organizations')
        ->assertNoJavaScriptErrors();
})->skip(! class_exists(AdminConsoleServiceProvider::class), 'The admin console is not installed.');
