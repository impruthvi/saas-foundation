<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

use App\Models\Organization;

/**
 * Raised when the organization stopped being usable after the invitation was sent.
 *
 * Suspension exists to stop access, so an invitation issued the day before must
 * not be a way around it. `SwitchOrganizationController` makes the same check
 * for the same reason; this is the door on the other side of the same room.
 */
final class OrganizationNotAcceptingMembers extends InvitationRefused
{
    public static function for(Organization $organization): self
    {
        return new self("{$organization->name} is not accepting new members right now.");
    }
}
