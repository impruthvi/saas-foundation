<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who is in this organization, and who has been asked.
 *
 * Both lists are scoped by the global scope rather than by anything written
 * here — that is the point of enforcing tenancy at the model boundary (D3).
 *
 * Both are also eager-loaded and paginated deliberately. Under
 * `ShouldBeStrict`, which `config/essentials.php` enables for every
 * environment and not only for tests, reading `$membership->user` without
 * loading it raises `LazyLoadingViolationException`. A missed eager load here
 * is a broken page on the second member, not a slow one.
 */
final class MemberController extends Controller
{
    private const int PER_PAGE = 25;

    public function index(Request $request, TenantContext $tenant): Response
    {
        Gate::authorize('viewAny', Invitation::class);

        $organization = $tenant->current();

        return Inertia::render('organizations/Members', [
            'members' => Membership::query()
                ->with('user:id,name,email')
                ->oldest('joined_at')
                ->orderBy('id')
                ->paginate(self::PER_PAGE, pageName: 'members')
                ->through(fn (Membership $membership): array => [
                    'id' => $membership->id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'role' => $membership->role->label(),
                    'status' => $membership->status->value,
                    'isOwner' => $organization instanceof Organization
                        && $organization->owner_id === $membership->user_id,
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
            'canInvite' => $request->user()?->can('create', Invitation::class) ?? false,
        ]);
    }
}
