<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Actions\DeliverOrganizationInvitation;
use App\Actions\ResendOrganizationInvitation;
use App\Exceptions\Invitations\InvitationRefused;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class InvitationDeliveryController extends Controller
{
    public function store(
        Invitation $invitation,
        ResendOrganizationInvitation $resend,
        DeliverOrganizationInvitation $deliver,
        TenantContext $tenant,
    ): RedirectResponse {
        Gate::authorize('update', $invitation);

        $organization = $tenant->current();

        abort_unless($organization instanceof Organization, HttpResponse::HTTP_FORBIDDEN);

        try {
            $issued = $resend->handle($invitation);
        } catch (InvitationRefused $invitationRefused) {
            return back()->withErrors(['invitation' => $invitationRefused->getMessage()]);
        }

        $deliver->handle($issued['invitation'], $organization, $issued['token']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent again.')]);

        return back();
    }
}
