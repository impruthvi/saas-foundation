<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Membership;
use App\Models\Organization;
use App\Tenancy\MembershipRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A switch to an organization without an active membership is a 403, not a silent
 * no-op.
 */
final class SwitchOrganizationController extends Controller
{
    public function __invoke(
        Request $request,
        Organization $organization,
        MembershipRepository $memberships,
    ): RedirectResponse {
        $user = $request->user();

        abort_if($user === null, Response::HTTP_FORBIDDEN);
        abort_if(! $memberships->activeMembership($user, $organization) instanceof Membership, Response::HTTP_FORBIDDEN);
        abort_unless($organization->status->isUsable(), Response::HTTP_FORBIDDEN);

        $request->session()->put(ResolveTenantContext::SESSION_KEY, $organization->id);

        return back();
    }
}
