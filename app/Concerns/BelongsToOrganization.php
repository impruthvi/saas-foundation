<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Contracts\TenantOwned;
use App\Exceptions\CrossTenantAccess;
use App\Exceptions\TenantContextMissing;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Makes a model's rows belong to one organization, and keeps them there.
 *
 * Three hooks, because one is not enough:
 *
 *   query     ──► TenantScope          constrains select, update and delete
 *   creating  ──► fill organization_id inserts never reach the scope
 *   retrieved ──► CrossTenantAccess    the paths that bypass the scope entirely
 *
 * That third one carries the weight the other two cannot. `Model::
 * newQueryForRestoration()` calls `newQueryWithoutScopes()`, so a queued job
 * carrying a serialized tenant-owned model restores it with the scope switched
 * off. The row still arrives through `retrieved`, which is where a mismatch
 * between the row's organization and the resolved one is turned into a failed
 * job rather than a silent cross-tenant read (D24).
 *
 * @phpstan-require-extends Model
 */
trait BelongsToOrganization
{
    /**
     * Boot the tenant behaviour for the model.
     */
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model&TenantOwned $model): void {
            $column = $model->tenantColumn();

            if ($model->getAttribute($column) !== null) {
                return;
            }

            $tenant = resolve(TenantContext::class);

            if (! $tenant->hasTenant()) {
                throw TenantContextMissing::forModel($model::class);
            }

            $model->setAttribute($column, $tenant->idOrFail());
        });

        static::retrieved(function (Model&TenantOwned $model): void {
            $tenant = resolve(TenantContext::class);

            if (! $tenant->hasTenant()) {
                return;
            }

            $column = $model->tenantColumn();
            $attributes = $model->getAttributes();

            if (! array_key_exists($column, $attributes)) {
                return;
            }

            $organizationId = $attributes[$column] === null ? null : (int) $attributes[$column];

            if ($organizationId !== $tenant->idOrFail()) {
                throw CrossTenantAccess::forModel($model::class, $organizationId, $tenant->idOrFail());
            }
        });
    }

    /**
     * The column carrying the owning organization's key.
     */
    public function tenantColumn(): string
    {
        return 'organization_id';
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Read or write across organizations, deliberately and visibly.
     *
     * Call sites are restricted by `tests/Unit/TenantScopingTest.php`; reaching
     * for this in the product surface fails that test by design (D22).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
