<?php

declare(strict_types=1);

namespace App\Exceptions\Memberships;

use App\Models\Organization;
use RuntimeException;

final class LastAdministrator extends RuntimeException
{
    public static function of(Organization $organization): self
    {
        return new self(
            "[{$organization->name}] would be left with no administrator, so this change was refused."
        );
    }
}
