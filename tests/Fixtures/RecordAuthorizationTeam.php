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
 * Separate from RecordResolvedTenant because the resolved tenant and the permission
 * team can disagree.
 */
final class RecordAuthorizationTeam implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly string $key) {}

    /**
     * Wrapped in an array because Cache::has() reports a stored null as absent, and
     * null is the answer this fixture exists to prove.
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
