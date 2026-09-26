<?php

declare(strict_types=1);

use App\Providers\BillingReplayServiceProvider;
use Symfony\Component\Finder\Finder;

arch()->preset()->php();
arch()->preset()->security();
arch()->preset()->laravel();

it('keeps Cashier subscription reads behind the billing facts boundary', function (): void {
    $allowed = [
        'app/Billing/BillingFacts.php',
        'app/Http/Controllers/Billing/StripeWebhookController.php',
    ];
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $path = 'app/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());

        if (in_array($path, $allowed, true)) {
            continue;
        }

        $contents = (string) file_get_contents($file->getRealPath());

        if (preg_match('/->(?:subscribed|subscription)\s*\(/', $contents) === 1) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Cashier subscription reads belong in BillingFacts: '.implode(', ', $offenders));
});

it('excludes the billing replay provider from production', function (): void {
    $environment = app()->environment();

    app()->detectEnvironment(fn (): string => 'production');

    try {
        $providers = require base_path('bootstrap/providers.php');
    } finally {
        app()->detectEnvironment(fn (): string => $environment);
    }

    expect($providers)->not->toContain(BillingReplayServiceProvider::class);
});

it('reads the entitlement package tables in exactly one place', function (): void {
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $path = 'app/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());

        if ($path !== 'app/Entitlements/RefreshReceipts.php' && str_contains((string) file_get_contents($file->getRealPath()), 'cashier_entitlement_')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'The package tables are read only through RefreshReceipts: '.implode(', ', $offenders));
});
