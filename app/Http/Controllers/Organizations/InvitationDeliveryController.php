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

/**
 * Sending an invitation again.
 *
 * A delivery is its own resource rather than a `resend` action bolted onto
 * InvitationController: "send this invitation again" creates something — a new
 * token, a new clock, a new message — and modelling it as a create keeps the
 * controller vocabulary standard. The architecture preset enforces that, which
 * is how this class came to exist.
 *
 * Resending rotates the token, so the previously emailed link stops working. The
 * newest email is the one that counts; the old link then resolves to nothing and
 * the accept screen says "no longer valid" rather than claiming it expired.
 */
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
        } catch (InvitationRefused $refused) {
            return back()->withErrors(['invitation' => $refused->getMessage()]);
        }

        $deliver->handle($issued['invitation'], $organization, $issued['token']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent again.')]);

        return back();
    }
}
