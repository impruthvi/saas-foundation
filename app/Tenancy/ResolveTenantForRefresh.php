<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Organization;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Impruthvi\CashierEntitlements\Jobs\RefreshOwner;

/**
 * RefreshOwner carries only an owner reference but reads subscriptions through a
 * tenant-scoped relation that raises without a tenant; without this pipe every queued
 * refresh fails. A bus pipe because the package job declares no middleware and stays
 * unpatched.
 */
final readonly class ResolveTenantForRefresh
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(mixed $command, Closure $next): mixed
    {
        if (! $command instanceof RefreshOwner) {
            return $next($command);
        }

        $owner = $command->owner;

        // Unrecognised owners pass through so they fail where they would anyway, not
        // under a misleading organization.
        if (Relation::getMorphedModel($owner->type) !== Organization::class || ! ctype_digit($owner->key)) {
            return $next($command);
        }

        return $this->tenant->runForId((int) $owner->key, fn (): mixed => $next($command));
    }
}
