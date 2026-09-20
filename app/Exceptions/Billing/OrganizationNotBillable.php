<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Models\Organization;
use RuntimeException;

final class OrganizationNotBillable extends RuntimeException
{
    public static function for(Organization $organization): self
    {
        return new self(
            "Billing cannot be changed while [{$organization->name}] is {$organization->status->value}."
        );
    }
}
