<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Organization;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Impruthvi\CashierEntitlements\Jobs\RefreshOwner;

/**
 * Resolves the organization a queued entitlement refresh belongs to.
 *
 * Why this exists, because a reader will otherwise delete it as ceremony:
 *
 *   RefreshOwner (owner reference only) ──▶ RefreshManager::refresh()
 *                                              │
 *                                              ▼
 *                                    $model->subscriptions()
 *                                              │
 *                                    TenantScope, no tenant ──▶ raise
 *
 * The job carries an `OwnerReference` rather than a model, so it never trips
 * the queue's serialization trap. It still reads the owner's subscriptions
 * through a tenant-scoped relation, and that scope raises rather than falling
 * back to everything. Without this pipe every queued refresh fails.
 *
 * The organization is recovered from the reference the work already carries,
 * exactly as the webhook recovers it from the Stripe customer. Nothing is read
 * across tenants, so this is not another audited door.
 *
 * It runs as a bus pipe rather than as job middleware because `RefreshOwner`
 * belongs to the package and declares neither a `middleware()` method nor a
 * `middleware` property, and the package stays unpatched. It lives beside the
 * tenant rather than under `app/Jobs` for the same reason: it is not a job.
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

        // An owner this application does not recognise is passed through so it
        // fails where it would have failed anyway, rather than here under a
        // misleading organization.
        if (Relation::getMorphedModel($owner->type) !== Organization::class || ! ctype_digit($owner->key)) {
            return $next($command);
        }

        return $this->tenant->runForId((int) $owner->key, fn (): mixed => $next($command));
    }
}
