<?php

declare(strict_types=1);

use App\Models\User;

it('offers a guest the way in, and nothing about the framework underneath', function (): void {
    visit('/')
        ->assertSee(config('app.name'))
        ->assertSee('Log in')
        ->assertSee('Register')
        ->assertDontSee('Laracasts')
        ->assertDontSee('Deploy now')
        ->assertNoJavaScriptErrors();
});

it('sends a signed-in user on to the dashboard', function (): void {
    $this->actingAs(User::factory()->create());

    visit('/')
        ->assertSee('Go to dashboard')
        ->assertDontSee('Register')
        ->assertNoJavaScriptErrors();
});
