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
 * Nothing binds a session-parked token to the account that finally signs in, so the
 * token is forgotten before use and acceptance still runs the full refusal ladder.
 */
final readonly class ConsumePendingInvitation
{
    public const string SESSION_KEY = 'pending_invitation_token';

    public function __construct(
        private InvitationRepository $invitations,
        private AcceptOrganizationInvitation $accept,
    ) {}

    /**
     * Returns null when nothing is usable: registration must not fail because an
     * invitation lapsed mid-form.
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
            // Reported, not thrown: registration succeeded, and a silently dropped
            // invitation leaves someone believing they joined.
            return $this->explain($invitationRefused->getMessage());
        }

        return $invitation;
    }

    private function explain(string $message): null
    {
        Inertia::flash('toast', ['type' => 'warning', 'message' => $message]);

        return null;
    }
}
