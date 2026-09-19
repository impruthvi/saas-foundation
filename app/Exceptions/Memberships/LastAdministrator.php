<?php

declare(strict_types=1);

namespace App\Exceptions\Memberships;

use App\Models\Organization;
use RuntimeException;

/**
 * Raised when a change would leave an organization with no administrator.
 *
 * The rule M3 introduces, and a rule rather than a guard clause: an
 * organization with nobody who may invite, promote or manage billing is not
 * recoverable from inside the product. `organizations.owner_id` is the
 * backstop for the owner specifically (D23), but rank is what the members
 * screen changes, so rank is where this has to be enforced.
 */
final class LastAdministrator extends RuntimeException
{
    public static function of(Organization $organization): self
    {
        return new self(
            "[{$organization->name}] would be left with no administrator, so this change was refused."
        );
    }
}
