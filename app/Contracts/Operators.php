<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;

/**
 * The admin console binds this. Without it nobody is an operator, so no impersonation
 * can start.
 */
interface Operators
{
    public function isOperator(User $user): bool;

    public function returnUrl(): string;
}
