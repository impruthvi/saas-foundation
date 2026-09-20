<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Models\Organization;
use RuntimeException;

final class AlreadySubscribed extends RuntimeException
{
    public static function for(Organization $organization): self
    {
        return new self("[{$organization->name}] already has an active subscription.");
    }
}
