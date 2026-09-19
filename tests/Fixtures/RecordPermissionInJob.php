<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Records whether a user held a permission at the moment the job ran.
 */
final class RecordPermissionInJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $key,
        private readonly User $user,
        private readonly string $permission,
    ) {}

    public static function recorded(string $key): ?bool
    {
        /** @var array{held: bool}|null $entry */
        $entry = Cache::get('job-permission.'.$key);

        return $entry['held'] ?? null;
    }

    public function handle(): void
    {
        Cache::put('job-permission.'.$this->key, [
            'held' => $this->user->unsetRelation('roles')
                ->unsetRelation('permissions')
                ->hasPermissionTo($this->permission),
        ]);
    }
}
