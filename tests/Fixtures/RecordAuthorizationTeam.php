<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;

/**
 * Records the team every role assignment would be read against, once run.
 *
 * Deliberately separate from `RecordResolvedTenant`: that fixture answers
 * "which organization did the job resolve", and this one answers "which
 * organization would `can()` have answered for". The whole point of D29 is
 * that those two can disagree, so a fixture that reported them as one value
 * could not show the disagreement.
 */
final class RecordAuthorizationTeam implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly string $key) {}

    /**
     * The team the job saw, or null.
     *
     * Wrapped in an array rather than stored bare, because null is the answer
     * this fixture exists to prove and `Cache::has()` reports a stored null as
     * absent — which would make "the job ran and saw nothing" indistinguishable
     * from "the job never ran".
     */
    public static function recorded(string $key): int|string|null
    {
        /** @var array{team: int|string|null}|null $entry */
        $entry = Cache::get('authorization-team.'.$key);

        return $entry['team'] ?? null;
    }

    public static function hasRun(string $key): bool
    {
        return Cache::has('authorization-team.'.$key);
    }

    public function handle(PermissionRegistrar $permissions): void
    {
        Cache::put('authorization-team.'.$this->key, ['team' => $permissions->getPermissionsTeamId()]);
    }
}
