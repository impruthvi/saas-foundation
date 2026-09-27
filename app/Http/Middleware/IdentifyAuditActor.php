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
 * A guest is still recorded as web. During an impersonation it rides along, so the log
 * never credits the user with the operator's act.
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
