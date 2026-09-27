<?php

declare(strict_types=1);

use App\Actions\SeedDemoJourney;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

it('rebuilds the database and seeds the demo again with --fresh', function (): void {
    User::factory()->create(['email' => 'someone@example.com']);
    Artisan::call('saas:demo');
    $firstOwnerPassword = User::query()->where('email', SeedDemoJourney::OWNER_EMAIL)->value('password');

    $exitCode = Artisan::call('saas:demo', ['--fresh' => true]);

    expect($exitCode)->toBe(0)
        ->and(User::query()->orderBy('email')->pluck('email')->all())
        ->toBe([SeedDemoJourney::OWNER_EMAIL, SeedDemoJourney::TEAMMATE_EMAIL])
        ->and(User::query()->where('email', SeedDemoJourney::OWNER_EMAIL)->value('password'))
        ->not->toBe($firstOwnerPassword);
});
