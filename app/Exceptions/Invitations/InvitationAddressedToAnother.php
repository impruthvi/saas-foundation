<?php

declare(strict_types=1);

namespace App\Exceptions\Invitations;

/**
 * Raised when the signed-in user is not the person the invitation names.
 *
 * The remedy is in the message because the common cause is mundane: the
 * recipient is already signed in to a different account in the same browser.
 * Accepting on behalf of whoever happens to be authenticated would put the wrong
 * person inside someone else's organization.
 */
final class InvitationAddressedToAnother extends InvitationRefused
{
    public static function addressedTo(string $email): self
    {
        return new self(
            "This invitation was sent to {$email}. Sign out and sign in as that account to accept it."
        );
    }
}
