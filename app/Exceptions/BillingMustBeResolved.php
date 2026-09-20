<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Organization;
use DateTimeInterface;
use RuntimeException;

final class BillingMustBeResolved extends RuntimeException
{
    public static function for(Organization $organization, ?DateTimeInterface $endsAt): self
    {
        if ($endsAt instanceof DateTimeInterface) {
            return new self(
                "The subscription for [{$organization->name}] ends on [{$endsAt->format('F j, Y')}]. Delete this account after billing has ended."
            );
        }

        return new self(
            "Cancel the subscription for [{$organization->name}] before deleting this account."
        );
    }
}
