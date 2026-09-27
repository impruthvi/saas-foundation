<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class DemoRefused extends RuntimeException
{
    public static function outsideLocal(string $environment): self
    {
        return new self("The demo prints the passwords of the accounts it seeds, so it runs only in local or testing, not in [{$environment}].");
    }

    public static function alreadySeeded(): self
    {
        return new self('The demo is already seeded. Run `php artisan saas:demo --fresh` to rebuild the database and seed it again.');
    }
}
