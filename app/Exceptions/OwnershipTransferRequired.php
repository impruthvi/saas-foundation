<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Organization;
use Illuminate\Support\Collection;
use RuntimeException;

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
