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
 * Keyed by token, not route-model-bound: there is no tenant until the invitation
 * resolves. The organization is named before sign-in on purpose, because an accept
 * screen that names nothing is indistinguishable from phishing.
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

        // Land in the inviting organization, not wherever the session pointed.
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

        // Declining requires the token and being the addressee; otherwise anyone who
        // saw the link could close it.
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
     * A rotated token is worded as no longer valid, never as expired.
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

    private function refusalFor(Invitation $invitation, ?User $user, AcceptOrganizationInvitation $accept): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        try {
            // Same ladder as the action, so the screen and the endpoint agree on the
            // reason.
            $accept->assertAcceptableBy($invitation, $user);
        } catch (InvitationRefused $invitationRefused) {
            return $invitationRefused->getMessage();
        }

        return null;
    }

    /**
     * Queried by key: lazy loading is prevented and no tenant is resolved.
     */
    private function organizationOf(Invitation $invitation): Organization
    {
        return Organization::query()->findOrFail($invitation->organization_id);
    }
}
