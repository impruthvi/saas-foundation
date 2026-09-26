<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Operator;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Let a person into the admin console.
 *
 * Granted from the command line only, so a stolen browser session cannot create
 * a second operator. The grant is logged rather than audited: an audit event
 * belongs to one organization, and this belongs to none.
 */
final readonly class GrantOperator
{
    public function handle(User $user, string $reason, ?User $grantedBy = null): Operator
    {
        throw_if(mb_trim($reason) === '', InvalidArgumentException::class, 'Say why this person needs the console.');
        throw_if(Operator::query()->where('user_id', $user->id)->exists(), InvalidArgumentException::class, "{$user->email} is already an operator.");

        $operator = Operator::query()->create([
            'user_id' => $user->id,
            'granted_by' => $grantedBy?->id,
            'reason' => mb_trim($reason),
            'granted_at' => now(),
        ]);

        Log::notice('Granted admin console access.', ['user_id' => $user->id, 'granted_by' => $grantedBy?->id, 'reason' => $operator->reason]);

        return $operator;
    }
}
