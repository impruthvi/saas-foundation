<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an invitation token from riding a Referer header off the site.
 *
 * The token sits in the URL path, which is the posture Laravel itself takes for
 * `password/reset/{token}`: an emailed link has to work on click, and every
 * alternative breaks that. The cost is that any off-site request the page makes
 * would otherwise carry the secret in `Referer`.
 *
 * `same-origin` keeps the full path for our own requests, which Inertia needs,
 * and sends nothing at all to anyone else. `no-referrer` would also work and
 * would break same-origin analytics and CSRF heuristics for no extra safety.
 *
 * Not a substitute for revocation — it narrows one leak, and the reason
 * revocation exists is that the others cannot all be closed.
 */
final class ProtectInvitationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
