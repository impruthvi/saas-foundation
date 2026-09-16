<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a row arrives from a different organization than the resolved one.
 *
 * The global scope cannot catch every path. `Model::newQueryForRestoration()`
 * calls `newQueryWithoutScopes()`, so a queued job carrying a serialized
 * tenant-owned model restores it with the scope bypassed; this guard runs on the
 * `retrieved` event, which that path does reach (D24).
 */
final class CrossTenantAccess extends RuntimeException
{
    public static function forModel(string $model, ?int $rowOrganizationId, int $resolvedOrganizationId): self
    {
        return new self(
            "Row of [{$model}] belongs to organization [".($rowOrganizationId ?? 'null')
            ."] but organization [{$resolvedOrganizationId}] is resolved."
        );
    }
}
