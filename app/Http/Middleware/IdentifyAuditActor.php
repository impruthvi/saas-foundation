<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Audit\AuditActor;
use App\Enums\AuditSource;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Names the person behind this request for anything it audits.
 *
 * A guest is still a web request, and says so, rather than leaving the act to
 * look like background work.
 */
final class IdentifyAuditActor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        ($user instanceof User ? AuditActor::user($user) : AuditActor::source(AuditSource::Web))->bind();

        return $next($request);
    }
}
