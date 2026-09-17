<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Organization;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Raised when an account cannot be deleted without orphaning an organization.
 *
 * The remedy is named in the message because there is one: transfer ownership,
 * then delete (D25).
 */
final class OwnershipTransferRequired extends RuntimeException
{
    /**
     * @param  Collection<int, Organization>  $organizations
     */
    public static function before(Collection $organizations): self
    {
        $names = $organizations->pluck('name')->implode(', ');

        return new self(
            "Ownership of [{$names}] has to be transferred before this account can be deleted."
        );
    }
}
