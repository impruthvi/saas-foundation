<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an impersonation stopped.
 */
enum ImpersonationEnd: string
{
    case Operator = 'operator';
    case Expiry = 'expiry';
    case Logout = 'logout';
    case Revoked = 'revoked';

    /**
     * Whether the operator gets their own session back afterwards.
     *
     * A revoked operator is not handed back a session they no longer hold, and
     * one who logged out asked to leave.
     */
    public function restoresOperator(): bool
    {
        return $this === self::Operator || $this === self::Expiry;
    }
}
