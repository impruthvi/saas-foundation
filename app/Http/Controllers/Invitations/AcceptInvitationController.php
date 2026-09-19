<?php

declare(strict_types=1);

namespace App\Http\Controllers\Invitations;

use App\Actions\AcceptOrganizationInvitation;
use App\Actions\ConsumePendingInvitation;
use App\Actions\DeclineOrganizationInvitation;
use App\Exceptions\Invitations\InvitationRefused;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\InvitationRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * What a stranger holding a token sees, and what happens when they act on it.
 *
 * Keyed by token rather than route-model-bound because the request has no tenant
 * until the invitation is resolved.
 *
 *   GET /invitations/{token}
 *        │
 *        ├─ token resolves to nothing ──► 404, worded as "no longer valid"
 *        │                                 (never "expired" — a rotated token
 *        │                                  was replaced, not aged out)
 *        ├─ nobody signed in ──► park the token, offer register or sign in
 *        └─ signed in ──► show the offer, or the reason it cannot be taken
 *
 * The organization is named before authentication on purpose. The token is the
 * secret; an accept screen that names nothing is indistinguishable from
 * phishing, and the recipient cannot tell whether it is worth trusting.
 */
final class AcceptInvitationController extends Controller
{
    public function show(
        Request $request,
        string $token,
        InvitationRepository $invitations,
        AcceptOrganizationInvitation $accept,
    ): Response {
        $invitation = $this->findOrFail($token, $invitations);

        $user = $request->user();

        if (! $user instanceof User) {
            $request->session()->put(ConsumePendingInvitation::SESSION_KEY, $token);
        }

        return Inertia::render('invitations/Show', [
            'token' => $token,
            'organization' => $this->organizationOf($invitation)->name,
            'email' => $invitation->email,
            'invitedBy' => $invitation->invitedBy?->name,
            'expiresAt' => $invitation->expires_at->toFormattedDateString(),
            'refusal' => $this->refusalFor($invitation, $user, $accept),
            'authenticated' => $user instanceof User,
        ]);
    }

    public function store(
        Request $request,
        string $token,
        InvitationRepository $invitations,
        AcceptOrganizationInvitation $accept,
    ): RedirectResponse {
        $invitation = $this->findOrFail($token, $invitations);

        $user = $request->user();

        abort_unless($user instanceof User, HttpResponse::HTTP_FORBIDDEN);

        try {
            $accept->handle($invitation, $user);
        } catch (InvitationRefused $invitationRefused) {
            return back()->withErrors(['invitation' => $invitationRefused->getMessage()]);
        }

        // Land them inside the organization that invited them, not wherever
        // their session happened to be pointing.
        $request->session()->put(ResolveTenantContext::SESSION_KEY, $invitation->organization_id);
        $request->session()->forget(ConsumePendingInvitation::SESSION_KEY);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('You have joined :organization.', [
                'organization' => $this->organizationOf($invitation)->name,
            ]),
        ]);

        return to_route('dashboard');
    }

    public function destroy(
        Request $request,
        string $token,
        InvitationRepository $invitations,
        DeclineOrganizationInvitation $decline,
    ): RedirectResponse {
        $invitation = $this->findOrFail($token, $invitations);

        $user = $request->user();

        // Declining is authorized by holding the token AND being the addressee.
        // Without the second half, anyone who saw the link could close somebody
        // else's invitation.
        abort_unless(
            $user instanceof User && $invitation->wasAddressedTo($user->email),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $decline->handle($invitation);

        $request->session()->forget(ConsumePendingInvitation::SESSION_KEY);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        return to_route('home');
    }

    /**
     * A token that names nothing is a 404, and says so honestly.
     */
    private function findOrFail(string $token, InvitationRepository $invitations): Invitation
    {
        $invitation = $invitations->findByToken($token);

        abort_if(
            ! $invitation instanceof Invitation,
            HttpResponse::HTTP_NOT_FOUND,
            __('This invitation link is no longer valid. If you were invited, check your most recent email or ask for a new invitation.'),
        );

        return $invitation;
    }

    /**
     * Why this person cannot take this invitation, in their own words.
     *
     * Rendered rather than thrown: the screen exists to explain, and a stranger
     * who is signed in to the wrong account needs to be told which account, not
     * shown an error page.
     */
    private function refusalFor(Invitation $invitation, ?User $user, AcceptOrganizationInvitation $accept): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        try {
            // The action's own ladder, asked without acting on the answer, so
            // this screen and the endpoint cannot drift apart about the reason.
            $accept->assertAcceptableBy($invitation, $user);
        } catch (InvitationRefused $invitationRefused) {
            return $invitationRefused->getMessage();
        }

        return null;
    }

    /**
     * The organization this invitation belongs to.
     *
     * `organizations` is not tenant-owned — it IS the tenant — so no scope is
     * stood down to read it, and none needs to be. It is a query rather than
     * `$invitation->organization` because lazy loading is prevented application
     * wide and this request has no tenant resolved.
     */
    private function organizationOf(Invitation $invitation): Organization
    {
        return Organization::query()->findOrFail($invitation->organization_id);
    }
}
