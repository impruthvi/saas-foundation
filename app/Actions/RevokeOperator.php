<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ImpersonationEnd;
use App\Models\Impersonation;
use App\Models\Operator;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Take a person's admin console access away, including anything they are
 * doing as another user right now.
 *
 * Their live impersonations are closed here so the history says why, and the
 * impersonation guard signs the browser out on its next request.
 */
final readonly class RevokeOperator
{
    public function handle(User $user): bool
    {
        $revoked = DB::transaction(function () use ($user): bool {
            $deleted = Operator::query()->where('user_id', $user->id)->delete();

            Impersonation::query()
                ->where('operator_id', $user->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => now(), 'ended_by' => ImpersonationEnd::Revoked]);

            return $deleted > 0;
        });

        if ($revoked) {
            Log::notice('Revoked admin console access.', ['user_id' => $user->id]);
        }

        return $revoked;
    }
}
