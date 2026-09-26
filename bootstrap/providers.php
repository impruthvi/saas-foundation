<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\BillingReplayServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\Filament\AdminConsoleServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\TenancyServiceProvider;
use Impruthvi\CashierDunning\CashierDunning;

$providers = [
    AppServiceProvider::class,
    BillingServiceProvider::class,
    AdminConsoleServiceProvider::class,
    FortifyServiceProvider::class,
    TenancyServiceProvider::class,
];

if (! app()->isProduction() && class_exists(CashierDunning::class)) {
    $providers[] = BillingReplayServiceProvider::class;
}

return $providers;
