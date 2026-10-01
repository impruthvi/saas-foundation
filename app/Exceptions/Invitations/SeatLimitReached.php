<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

use App\Models\Organization;

final class SeatLimitReached extends InvitationRefused
{
    public static function for(Organization $organization): self
    {
        return new self("{$organization->name} has reached its seat limit. Remove a membership or change the plan before accepting another invitation.");
    }
}
