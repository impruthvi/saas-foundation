<?php

declare(strict_types=1);

use App\Actions\SeedDemoJourney;
use App\Models\Operator;
use Filament\Facades\Filament;

it('lets the demo owner into the admin console', function (): void {
    $owner = resolve(SeedDemoJourney::class)->handle()['owner'];

    expect(Operator::query()->where('user_id', $owner->id)->value('reason'))->toBe('saas:demo')
        ->and($owner->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});
