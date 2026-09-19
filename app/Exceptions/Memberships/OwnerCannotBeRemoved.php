<?php

declare(strict_types=1);

namespace App\Exceptions\Memberships;

use App\Models\Organization;
use RuntimeException;

/**
 * Raised when a removal would take the owner out of their own organization.
 *
 * The same rule account deletion already enforces, from the other direction
 * (D25): an organization is never orphaned, and the remedy is named because
 * there is one. `organizations.owner_id` restricts on delete underneath this,
 * so the database refuses it too.
 */
final class OwnerCannotBeRemoved extends RuntimeException
{
    public static function of(Organization $organization): self
    {
        return new self(
            "The owner cannot be removed from [{$organization->name}]. Transfer ownership first."
        );
    }
}
