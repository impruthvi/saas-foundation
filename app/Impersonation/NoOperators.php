<?php

declare(strict_types=1);

namespace App\Impersonation;

use App\Contracts\Operators;
use App\Models\User;

/**
 * The answer when no admin console is installed: nobody is an operator.
 */
final readonly class NoOperators implements Operators
{
    public function isOperator(User $user): bool
    {
        return false;
    }

    public function returnUrl(): string
    {
        return route('dashboard');
    }
}
