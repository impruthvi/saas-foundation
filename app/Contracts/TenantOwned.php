<?php

declare(strict_types=1);

namespace App\Contracts;

/** Marks a model whose every row belongs to exactly one organization. */
interface TenantOwned
{
    /**
     * The column carrying the owning organization's key.
     */
    public function tenantColumn(): string;
}
