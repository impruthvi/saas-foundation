<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\Operators;
use App\Enums\ImpersonationEnd;
use App\Models\Impersonation;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;

/**
 * Stop acting as a user, and hand the operator back if they may have it.
 *
 * The session is invalidated for the same reason it was on the way in, so
 * nothing from the user's session reaches the operator's. The operator is signed
 * back in only when the impersonation ended by their choice or by its clock, and
 * only while they are still an operator. Otherwise the browser is signed out on
 * this device alone. A full logout would cycle the user's remember token and end
 * their own sessions everywhere, which is not the operator's to do.
 */
final readonly class EndImpersonation
{
    public function __construct(
        private Operators $operators,
        private StatefulGuard $guard,
    ) {}

    public function handle(Session $session, ImpersonationEnd $reason): ?User
    {
        $impersonation = Impersonation::liveIn($session);

        $impersonation?->forceFill(['ended_at' => now(), 'ended_by' => $reason])->save();

        $operator = $impersonation?->operator;
        $restore = $reason->restoresOperator()
            && $operator instanceof User
            && $this->operators->isOperator($operator);

        $session->invalidate();
        $session->regenerateToken();

        if ($restore) {
            $this->guard->login($operator);

            return $operator;
        }

        $this->guard instanceof SessionGuard
            ? $this->guard->logoutCurrentDevice()
            : $this->guard->logout();

        return null;
    }
}
