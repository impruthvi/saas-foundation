<?php

declare(strict_types=1);

namespace App\Actions;

use App\Mail\OrganizationInvitation;
use App\Models\Invitation;
use App\Models\Organization;
use Illuminate\Support\Facades\Mail;

/**
 * Queues the message carrying the one usable copy of an invitation's token.
 *
 * Issuing and resending both end here, which is why it is an action rather than
 * a private method on either controller: the link they send has to be the same
 * link, and two copies of that logic is one copy too many.
 *
 * URL generation lives here rather than in the mailable. Routing is a delivery
 * concern, and keeping it out of the message means the message renders in a test
 * with no route table.
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
