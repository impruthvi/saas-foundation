<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a tenant-owned model is queried with no organization resolved.
 *
 * The alternative — returning every tenant's rows — is the failure this module
 * exists to prevent, so the boundary fails loudly instead of quietly (D3, D20).
 */
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
