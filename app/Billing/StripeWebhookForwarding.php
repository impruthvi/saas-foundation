<?php

declare(strict_types=1);

namespace App\Billing;

use Illuminate\Foundation\DevCommands;
use Symfony\Component\Process\ExecutableFinder;

final class StripeWebhookForwarding
{
    /**
     * Adds a stripe listen process to composer dev. Called only from the console, so no
     * web request pays for the PATH lookup, and it uses the same test key saas:stripe
     * used, so its signing secret matches the one written to .env.
     */
    public static function registerDevCommand(): void
    {
        $secret = config('cashier.secret');

        if (! is_string($secret) || ! StripeSecret::isTestKey($secret) || new ExecutableFinder()->find('stripe') === null) {
            return;
        }

        DevCommands::register('stripe listen --api-key '.escapeshellarg($secret).' --forward-to '.escapeshellarg(self::webhookUrl()), 'stripe');
    }

    public static function webhookUrl(): string
    {
        return mb_rtrim(config()->string('app.url'), '/').'/'.config()->string('cashier.path', 'stripe').'/webhook';
    }
}
