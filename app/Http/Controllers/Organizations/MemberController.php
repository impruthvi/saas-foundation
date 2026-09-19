<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Actions\ChangeOrganizationMemberRole;
use App\Actions\RemoveOrganizationMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\ChangeMemberRoleRequest;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Who is in this organization, who has been asked, and what may be done about it.
 *
 * Both are also eager-loaded and paginated deliberately. Under
 * `ShouldBeStrict`, which `config/essentials.php` enables for every
 * environment and not only for tests, reading `$membership->user` without
 * loading it raises `LazyLoadingViolationException`. A missed eager load here
 * is a broken page on the second member, not a slow one.
 *
 * The per-row flags are derived from one policy call plus one aggregate, never
 * from a policy call per row: `Membership::mayBeRemovedFrom()` is the same
 * predicate `MembershipPolicy` uses, so a button can only appear where the
 * endpoint would also allow it.
 */
final class MemberController extends Controller
{
    private const int PER_PAGE = 25;

    public function index(Request $request, TenantContext $tenant): Response
    {
        Gate::authorize('viewAny', Invitation::class);

        $organization = $tenant->current();
        $user = $request->user();

        $mayManage = $user?->can('manage', Membership::class) ?? false;

        // One aggregate for the whole screen: "is anyone else still running
        // this organization" is not a row-local question, and the last
        // administrator can sit on any page.
        $administrators = Membership::query()->administrators()->count();

        return Inertia::render('organizations/Members', [
            'members' => Membership::query()
                ->with('user:id,name,email')
                ->oldest('joined_at')
                ->orderBy('id')
                ->paginate(self::PER_PAGE, pageName: 'members')
                ->through(fn (Membership $membership): array => [
                    'id' => $membership->id,
                    'userId' => $membership->user_id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'role' => $membership->role->value,
                    'roleLabel' => $membership->role->label(),
                    'status' => $membership->status->value,
                    'isOwner' => $organization instanceof Organization
                        && $organization->owner_id === $membership->user_id,
                    'isYou' => $membership->user_id === $user?->id,
                    'canManage' => $mayManage && $organization instanceof Organization
                        && $membership->mayBeRemovedFrom(
                            $organization,
                            $administrators - ($membership->isActiveAdministrator() ? 1 : 0),
                        ),
                ]),
            'invitations' => Invitation::query()
                ->pending()
                ->with('invitedBy:id,name')
                ->latest('id')
                ->paginate(self::PER_PAGE, pageName: 'invitations')
                ->through(fn (Invitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->label(),
                    'expiresAt' => $invitation->expires_at->toFormattedDateString(),
                    'invitedBy' => $invitation->invitedBy?->name,
                ]),
            'canInvite' => $user?->can('create', Invitation::class) ?? false,
        ]);
    }

    public function update(
        ChangeMemberRoleRequest $request,
        Membership $membership,
        ChangeOrganizationMemberRole $change,
    ): RedirectResponse {
        try {
            $change->handle($membership, $request->role());
        } catch (RuntimeException $runtimeException) {
            return back()->withErrors(['role' => $runtimeException->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return back();
    }

    /** Removing yourself requires redirecting through tenant resolution again. */
    public function destroy(
        Request $request,
        Membership $membership,
        RemoveOrganizationMember $remove,
    ): RedirectResponse {
        Gate::authorize('delete', $membership);

        $removingSelf = $membership->user_id === $request->user()?->id;

        try {
            $remove->handle($membership);
        } catch (RuntimeException $runtimeException) {
            return back()->withErrors(['member' => $runtimeException->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $removingSelf ? __('You have left the organization.') : __('Member removed.'),
        ]);

        return $removingSelf
            ? to_route('dashboard')
            : back();
    }
}
