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
        $names = $organizations->pluck('name')->join(', ', ' and ');
        $verb = $organizations->count() === 1 ? 'has' : 'have';

        return new self(
            "{$names} {$verb} other members. Remove them from the organization before deleting this account."
        );
    }
}
