<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

use App\Models\Organization;

final class OrganizationNotAcceptingMembers extends InvitationRefused
{
    public static function for(Organization $organization): self
    {
        return new self("{$organization->name} is not accepting new members right now.");
    }
}
