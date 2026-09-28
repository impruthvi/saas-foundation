<?php

declare(strict_types=1);

namespace App\Exceptions\Memberships;

use App\Models\Organization;

final class OwnerCannotBeDemoted extends MembershipRefused
{
    public static function of(Organization $organization): self
    {
        return new self(
            "The owner of [{$organization->name}] cannot be demoted. Transfer ownership first."
        );
    }
}
