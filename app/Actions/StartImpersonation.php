<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\Operators;
use App\Exceptions\ImpersonationRefused;
use App\Models\Impersonation;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;

/**
 * Sign an operator in as another user, for a bounded and stated purpose.
 *
 * The session is invalidated rather than regenerated. Regenerating keeps every
 * attribute, including the operator's own password confirmation, which would
 * pass the user's "confirm your password" gates. Only the two impersonation keys
 * are written back, so anything nobody thought of is dropped rather than carried.
 * The organization the operator had selected goes with the rest, and the user's
 * default is resolved on the next request.
 */
final readonly class StartImpersonation
{
    public const int MINUTES = 30;

    public function __construct(
        private Operators $operators,
        private StatefulGuard $guard,
    ) {}

    public function handle(User $operator, User $user, string $reason, Session $session): Impersonation
    {
        $this->refuseUnlessAllowed($operator, $user, $reason);

        $impersonation = Impersonation::query()->create([
            'operator_id' => $operator->id,
            'user_id' => $user->id,
            'reason' => mb_trim($reason),
            'started_at' => now(),
            'expires_at' => now()->addMinutes(self::MINUTES),
        ]);

        $session->invalidate();
        $session->regenerateToken();

        $this->guard->login($user);

        $session->put(Impersonation::SESSION_KEY, $impersonation->id);
        $session->put(Impersonation::IMPERSONATOR_SESSION_KEY, $operator->id);

        return $impersonation;
    }

    /**
     * @throws ImpersonationRefused
     */
    private function refuseUnlessAllowed(User $operator, User $user, string $reason): void
    {
        throw_unless($this->operators->isOperator($operator), ImpersonationRefused::notAnOperator());
        throw_if($operator->is($user), ImpersonationRefused::ofSelf());
        throw_if($this->operators->isOperator($user), ImpersonationRefused::ofAnOperator());
        throw_if($user->email_verified_at === null, ImpersonationRefused::ofUnverifiedUser());
        throw_if(mb_trim($reason) === '', ImpersonationRefused::withoutReason());
    }
}
