<?php

declare(strict_types=1);

namespace App\Exceptions\Memberships;

use App\Models\Organization;

final class LastAdministrator extends MembershipRefused
{
    public static function of(Organization $organization): self
    {
        return new self(
            "[{$organization->name}] would be left with no administrator, so this change was refused."
        );
    }
}
