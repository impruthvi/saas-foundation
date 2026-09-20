<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Models\Organization;
use RuntimeException;

final class NoActiveSubscription extends RuntimeException
{
    public static function for(Organization $organization): self
    {
        return new self("[{$organization->name}] has no active subscription to cancel.");
    }
}
