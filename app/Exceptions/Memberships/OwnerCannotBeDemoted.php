<?php

declare(strict_types=1);

namespace App\Exceptions\Memberships;

use App\Models\Organization;
use RuntimeException;

/**
 * Raised when a change would drop the owner below Admin.
 *
 * Ownership is `organizations.owner_id` and rank is a separate fact (D23), so
 * nothing in the database stops the two disagreeing. They must not: from M4 the
 * owner is who gets billed, and an owner who cannot manage billing is a support
 * ticket that no in-app path resolves.
 */
final class OwnerCannotBeDemoted extends RuntimeException
{
    public static function of(Organization $organization): self
    {
        return new self(
            "The owner of [{$organization->name}] cannot be demoted. Transfer ownership first."
        );
    }
}
