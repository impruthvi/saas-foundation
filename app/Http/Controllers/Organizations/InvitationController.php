<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Actions\DeliverOrganizationInvitation;
use App\Actions\InviteOrganizationMember;
use App\Actions\RevokeOrganizationInvitation;
use App\Exceptions\Invitations\InvitationRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\InviteMemberRequest;
use App\Models\Invitation;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class InvitationController extends Controller
{
    public function store(
        InviteMemberRequest $request,
        InviteOrganizationMember $invite,
        DeliverOrganizationInvitation $deliver,
        TenantContext $tenant,
    ): RedirectResponse {
        $organization = $tenant->current();

        abort_unless($organization instanceof Organization, HttpResponse::HTTP_FORBIDDEN);

        try {
            $issued = $invite->handle(
                $organization,
                $request->string('email')->value(),
                $request->role(),
                $request->user(),
            );
        } catch (InvitationRefused $invitationRefused) {
            return back()->withErrors(['email' => $invitationRefused->getMessage()]);
        }

        $deliver->handle($issued['invitation'], $organization, $issued['token']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return back();
    }

    public function destroy(
        Invitation $invitation,
        RevokeOrganizationInvitation $revoke,
    ): RedirectResponse {
        Gate::authorize('delete', $invitation);

        try {
            $revoke->handle($invitation, request()->user());
        } catch (InvitationRefused $invitationRefused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $invitationRefused->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation withdrawn.')]);

        return back();
    }
}
