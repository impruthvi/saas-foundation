<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class TenantContextMissing extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self(
            "Refusing to query [{$model}] with no organization resolved. Wrap the work in "
            .'TenantContext::runFor(), or state the exception with withoutTenantScope().'
        );
    }
}
