<?php

declare(strict_types=1);

namespace App\Contracts;

interface TenantOwned
{
    public function tenantColumn(): string;
}
