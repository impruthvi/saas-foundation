<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Audit\AuditActor;
use App\Enums\AuditSource;
use App\Models\Impersonation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Names the person behind this request for anything it audits.
 *
 * A guest is still a web request, and says so, rather than leaving the act to
 * look like background work. While an operator is acting as the user, the
 * impersonation rides along, so the log never credits the user with the act.
 * The impersonation guard has already run, so an id in the session is live.
 */
final class IdentifyAuditActor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $impersonationId = $request->session()->get(Impersonation::SESSION_KEY);

        ($user instanceof User
            ? AuditActor::user($user, is_int($impersonationId) ? $impersonationId : null)
            : AuditActor::source(AuditSource::Web)
        )->bind();

        return $next($request);
    }
}
