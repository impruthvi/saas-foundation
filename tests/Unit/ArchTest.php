<?php

declare(strict_types=1);

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
