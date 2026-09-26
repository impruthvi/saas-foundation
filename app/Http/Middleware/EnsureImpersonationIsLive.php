<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\EndImpersonation;
use App\Contracts\Operators;
use App\Enums\ImpersonationEnd;
use App\Models\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an impersonation inside the bounds it was started with.
 *
 *   session names an impersonation
 *     ├─ row gone, ended, or naming another operator ──▶ signed out
 *     ├─ operator no longer an operator ──────────────▶ ended (revoked), signed out
 *     ├─ past its expiry ─────────────────────────────▶ ended (expiry), operator back
 *     ├─ logout ──────────────────────────────────────▶ ended (logout), this device only
 *     ├─ a refused route ─────────────────────────────▶ 403
 *     └─ otherwise ───────────────────────────────────▶ continue as the user
 *
 * Runs before the tenant is resolved, because ending an impersonation changes
 * who is signed in and therefore which organization applies.
 */
final readonly class EnsureImpersonationIsLive
{
    /**
     * What an operator may not do while acting as someone else: change how the
     * user signs in, close their account, move money, or join or turn down an
     * organization on their behalf.
     *
     * @var list<string>
     */
    public const array REFUSED_ROUTES = [
        'profile.update',
        'profile.destroy',
        'security.edit',
        'user-password.update',
        'password.confirm.store',
        'passkey.confirm',
        'passkey.store',
        'passkey.registration-options',
        'passkey.destroy',
        'two-factor.enable',
        'two-factor.disable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'organizations.billing.checkout.store',
        'organizations.billing.subscription.destroy',
        'organizations.billing.subscription.update',
        'invitations.accept',
        'invitations.decline',
    ];

    public function __construct(
        private Operators $operators,
        private EndImpersonation $end,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();

        if (! $session->has(Impersonation::SESSION_KEY)) {
            return $next($request);
        }

        $impersonation = Impersonation::liveIn($session);

        if (! $impersonation instanceof Impersonation) {
            $this->end->handle($session, ImpersonationEnd::Revoked);

            return $this->leave($request, route('login'));
        }

        $operator = $impersonation->operator;

        if ($operator === null || ! $this->operators->isOperator($operator)) {
            $this->end->handle($session, ImpersonationEnd::Revoked);

            return $this->leave($request, route('login'));
        }

        if ($impersonation->hasExpired()) {
            $this->end->handle($session, ImpersonationEnd::Expiry);

            return $this->leave($request, $this->operators->returnUrl());
        }

        $route = $request->route()?->getName();

        if ($route === 'logout') {
            $this->end->handle($session, ImpersonationEnd::Logout);

            return $this->leave($request, route('home'));
        }

        abort_if(
            in_array($route, self::REFUSED_ROUTES, true),
            Response::HTTP_FORBIDDEN,
            'This is not available while acting as another user.',
        );

        return $next($request);
    }

    private function leave(Request $request, string $url): Response
    {
        return $request->header('X-Inertia') === 'true'
            ? Inertia::location($url)
            : redirect()->to($url);
    }
}
