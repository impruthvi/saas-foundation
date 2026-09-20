<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Models\Organization;
use RuntimeException;

final class SubscriptionNotCancelled extends RuntimeException
{
    public static function for(Organization $organization): self
    {
        return new self("[{$organization->name}] does not have a cancelled subscription that can be resumed.");
    }
}
