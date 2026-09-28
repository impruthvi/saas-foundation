<?php

declare(strict_types=1);

namespace App\Exceptions\Memberships;

use App\Models\Organization;

final class OwnerCannotBeRemoved extends MembershipRefused
{
    public static function of(Organization $organization): self
    {
        return new self(
            "The owner cannot be removed from [{$organization->name}]. Transfer ownership first."
        );
    }
}
