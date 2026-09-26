<?php

declare(strict_types=1);

use App\Enums\ImpersonationEnd;
use App\Models\Impersonation;
use App\Models\Operator;
use App\Models\User;
use App\Providers\Filament\AdminConsoleServiceProvider;

/*
 * Ab2: an operator starts acting as a user from the console, works in the
 * product under a banner that says so, and ends it from that banner.
 */

it('acts as a user from the console and ends it from the banner', function (): void {
    [, $alice] = organizationOwnedBySomeone('Alice Co');
    $alice->forceFill(['name' => 'Alice Customer'])->save();
    $operator = User::factory()->create(['name' => 'Carol Operator']);
    Operator::factory()->create(['user_id' => $operator->id]);
    $this->actingAs($operator);

    $page = visit("/admin/users/{$alice->getRouteKey()}");

    $page->click('Act as this user')
        ->type('.fi-modal-window textarea', 'Ticket 88: cannot see her projects')
        ->press('Submit')
        ->assertPathIs('/dashboard')
        ->assertSee('Acting as Alice Customer for Carol Operator')
        ->assertNoJavaScriptErrors();

    $page->click('End')
        ->assertPathBeginsWith('/admin')
        ->assertDontSee('Acting as')
        ->assertNoJavaScriptErrors();

    expect(Impersonation::query()->sole()->ended_by)->toBe(ImpersonationEnd::Operator);
})->skip(! class_exists(AdminConsoleServiceProvider::class), 'The admin console is not installed.');
