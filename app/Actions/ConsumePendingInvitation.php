<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\Invitations\InvitationRefused;
use App\Models\Invitation;
use App\Models\User;
use App\Tenancy\InvitationRepository;
use Illuminate\Contracts\Session\Session;
use Inertia\Inertia;

/**
 * Takes the invitation a visitor arrived holding, once they have an account.
 *
 * A stranger who clicks an invitation link usually has no account, so the token
 * is parked in the session and they are sent to register. This is the other end
 * of that: called after registration, it spends the parked token and puts the
 * new user inside the organization that asked for them.
 *
 * Nothing binds a session-parked token to the identity that eventually
 * authenticates — the visitor may abandon registration and sign in as somebody
 * else entirely in the same browser. So the token is FORGOTTEN FIRST, before it
 * is used, and acceptance still runs the full refusal ladder: a mismatch raises
 * `InvitationAddressedToAnother` rather than quietly admitting the wrong person.
 * Forgetting first also means a refusal cannot leave a live token in the session
 * to be retried against the next account.
 */
final readonly class ConsumePendingInvitation
{
    /**
     * The session key holding a token its visitor has not yet been able to use.
     */
    public const string SESSION_KEY = 'pending_invitation_token';

    public function __construct(
        private InvitationRepository $invitations,
        private AcceptOrganizationInvitation $accept,
    ) {}

    /**
     * Accept whatever the session was holding, if anything, and if it still works.
     *
     * Returns null when there was nothing to take. Registration must not fail
     * because an invitation lapsed while somebody was filling in a form.
     */
    public function handle(Session $session, User $user): ?Invitation
    {
        $token = $session->pull(self::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            return null;
        }

        $invitation = $this->invitations->findByToken($token);

        if (! $invitation instanceof Invitation) {
            return $this->explain(__('That invitation link is no longer valid, so your account was created on its own. Ask for a new invitation.'));
        }

        try {
            $this->accept->handle($invitation, $user);
        } catch (InvitationRefused $invitationRefused) {
            // Registration still succeeded, so the refusal is reported rather
            // than thrown — but it IS reported. Dropping an invitation in
            // silence leaves somebody believing they joined an organization
            // they are not in, which they have no way to discover.
            return $this->explain($invitationRefused->getMessage());
        }

        return $invitation;
    }

    /**
     * Tell the new account holder why they are not where they expected to be.
     */
    private function explain(string $message): null
    {
        Inertia::flash('toast', ['type' => 'warning', 'message' => $message]);

        return null;
    }
}
