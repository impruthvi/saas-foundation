<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ImpersonationRefused extends RuntimeException
{
    public static function notAnOperator(): self
    {
        return new self('Only an operator can act as another user.');
    }

    public static function ofSelf(): self
    {
        return new self('You cannot act as yourself.');
    }

    public static function ofAnOperator(): self
    {
        return new self('Operators cannot act as other operators.');
    }

    public static function ofUnverifiedUser(): self
    {
        return new self('This user has not verified their email address yet.');
    }

    public static function withoutReason(): self
    {
        return new self('Say why you need to act as this user.');
    }
}
