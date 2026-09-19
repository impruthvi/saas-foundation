<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Catches cross-tenant rows restored through paths that bypass global scopes. */
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
