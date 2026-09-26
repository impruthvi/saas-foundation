<?php

declare(strict_types=1);

namespace App\Enums;

enum ImpersonationEnd: string
{
    case Operator = 'operator';
    case Expiry = 'expiry';
    case Logout = 'logout';
    case Revoked = 'revoked';

    /**
     * A revoked operator is not handed back a session, and one who logged out asked to
     * leave.
     */
    public function restoresOperator(): bool
    {
        return $this === self::Operator || $this === self::Expiry;
    }
}
