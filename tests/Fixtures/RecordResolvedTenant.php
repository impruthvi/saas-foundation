<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Records which organization the job resolved, and what it could see.
 *
 * Carries no organization of its own on purpose: whatever it resolves, it
 * resolved from the queue payload.
 */
final class RecordResolvedTenant implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly string $key) {}

    /**
     * What the job saw, once it has run. Null while it has not.
     *
     * @return array{organization_id: int|null, visible_projects: int|null}|null
     */
    public static function recorded(string $key): ?array
    {
        /** @var array{organization_id: int|null, visible_projects: int|null}|null */
        return Cache::get('tenant-job.'.$key);
    }

    public function handle(TenantContext $tenant): void
    {
        Cache::put('tenant-job.'.$this->key, [
            'organization_id' => $tenant->id(),
            'visible_projects' => $tenant->hasTenant() ? Project::query()->count() : null,
        ]);
    }
}
