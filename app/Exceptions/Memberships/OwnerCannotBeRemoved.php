<?php

declare(strict_types=1);

namespace App\Exceptions\Memberships;

use App\Models\Organization;
use RuntimeException;

final class OwnerCannotBeRemoved extends RuntimeException
{
    public static function of(Organization $organization): self
    {
        return new self(
            "The owner cannot be removed from [{$organization->name}]. Transfer ownership first."
        );
    }
}
