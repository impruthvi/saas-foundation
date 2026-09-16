<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Marks a model whose every row belongs to exactly one organization.
 *
 * Implementing this is a claim with teeth. `App\Concerns\BelongsToOrganization`
 * supplies the behaviour, `App\Tenancy\TenantScope` enforces it on every query,
 * and the suite-wide query guard in `tests/Support/TenantQueryGuard.php` fails
 * any test whose SQL reaches one of these tables without an `organization_id`
 * predicate (D20).
 */
interface TenantOwned
{
    /**
     * The column carrying the owning organization's key.
     */
    public function tenantColumn(): string;
}
