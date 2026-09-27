<?php

declare(strict_types=1);

namespace App\Actions;

use App\Mail\OrganizationInvitation;
use App\Models\Invitation;
use App\Models\Organization;
use Illuminate\Support\Facades\Mail;

/**
 * Shared by issuing and resending so both send the same link.
 */
final readonly class DeliverOrganizationInvitation
{
    public function handle(Invitation $invitation, Organization $organization, string $token): void
    {
        Mail::to($invitation->email)->queue(new OrganizationInvitation(
            $invitation,
            $organization->name,
            route('invitations.show', ['token' => $token]),
        ));
    }
}
