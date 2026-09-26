<?php

declare(strict_types=1);

use App\Models\Operator;
use App\Providers\Filament\AdminConsoleServiceProvider;
use Inertia\Testing\AssertableInertia;

// Outside tests/Feature/AdminConsole so it survives the console's removal and then
// proves the link reaches nobody.

it('shares the console address with an operator', function (): void {
    [, $operator] = organizationOwnedBySomeone();
    Operator::factory()->create(['user_id' => $operator->id]);

    $this->actingAs($operator)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('adminConsoleUrl', url('/admin')));
})->skip(! class_exists(AdminConsoleServiceProvider::class), 'The admin console is not installed.');

it('shares no console address with someone who is not an operator', function (): void {
    [, $member] = organizationOwnedBySomeone();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('adminConsoleUrl', null));
})->skip(! class_exists(AdminConsoleServiceProvider::class), 'The admin console is not installed.');

it('shares no console address at all once the console is removed', function (): void {
    [, $user] = organizationOwnedBySomeone();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->missing('adminConsoleUrl'));
})->skip(class_exists(AdminConsoleServiceProvider::class), 'The admin console is installed.');
