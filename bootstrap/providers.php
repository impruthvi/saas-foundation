<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    BillingServiceProvider::class,
    FortifyServiceProvider::class,
    TenancyServiceProvider::class,
];
