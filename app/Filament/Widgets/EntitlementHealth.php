<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Diagnostics\Doctor;

/**
 * The entitlement package's own health report, across every organization.
 *
 * Counts only. An operator follows a count into the organization it concerns.
 */
final class EntitlementHealth extends StatsOverviewWidget
{
    protected ?string $heading = 'Entitlement refreshes';

    protected function getStats(): array
    {
        $state = resolve(Doctor::class)->report(Date::now()->toDateTimeImmutable())['state'];

        return [
            Stat::make('Organizations tracked', (string) $state['owners']),
            Stat::make('Waiting to refresh', (string) $state['pending'])->color($state['pending'] > 0 ? 'warning' : 'gray'),
            Stat::make('Last refresh failed', (string) $state['failing'])->color($state['failing'] > 0 ? 'danger' : 'gray'),
            Stat::make('Stale', (string) $state['stale'])->color($state['stale'] > 0 ? 'warning' : 'gray'),
            Stat::make('Older catalog', (string) $state['catalog_mismatch'])->color($state['catalog_mismatch'] > 0 ? 'warning' : 'gray'),
        ];
    }
}
