<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The token is in the URL path, like password resets, so same-origin keeps it out of
 * the Referer sent to other sites while keeping full paths for our own requests.
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
