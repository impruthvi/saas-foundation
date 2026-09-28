<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * A publishable key pasted into the secret slot is the common mistake, so the prefix is
 * checked rather than just the presence of a value, matching the entitlements package.
 */
final class StripeSecret
{
    public static function configured(): bool
    {
        $secret = config('cashier.secret');

        return is_string($secret) && preg_match('/^(sk|rk)_(test|live)_/', $secret) === 1;
    }

    /**
     * The fix is a developer's, so it is spelled out only where a developer is reading.
     */
    public static function setupHint(): ?string
    {
        return app()->environment('local') ? 'Run php artisan saas:stripe sk_test_YOUR_KEY.' : null;
    }

    public static function isTestKey(string $secret): bool
    {
        return preg_match('/^(sk|rk)_test_/', $secret) === 1;
    }

    public static function isLiveKey(mixed $secret): bool
    {
        return is_string($secret) && preg_match('/^(sk|rk)_live_/', $secret) === 1;
    }
}
