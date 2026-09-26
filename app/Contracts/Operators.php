<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;

/**
 * Who may act as another user, as far as the product can tell.
 *
 * The admin console owns the list of operators and binds this. Without the
 * console nobody is an operator, so no impersonation can start and any that was
 * live ends on its next request.
 */
interface Operators
{
    public function isOperator(User $user): bool;

    /**
     * Where an operator lands when an impersonation hands them back.
     */
    public function returnUrl(): string;
}
