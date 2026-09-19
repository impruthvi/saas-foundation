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
 * Applies tenant scoping on reads, fills the tenant on inserts, and rejects
 * cross-tenant models restored through paths that bypass global scopes.
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

        $guardAgainstOtherTenants = function (Model&TenantOwned $model): void {
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
        };

        // Writing is guarded on the way in as reading is on the way out: an
        // instance loaded for one organization cannot be saved or deleted while
        // another is resolved, and it fails loudly rather than updating nothing.
        static::saving($guardAgainstOtherTenants);
        static::deleting($guardAgainstOtherTenants);

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
     * Constrain writes against an existing row by tenant as well as by key.
     *
     * Eloquent addresses a loaded model by primary key alone, so `$model->save()`
     * and `$model->delete()` never reach the global scope. Adding the tenant
     * predicate here means an instance carrying one organization's key cannot
     * write to another's row.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        parent::setKeysForSaveQuery($query);

        return $query->where($this->tenantColumn(), $this->getAttribute($this->tenantColumn()));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery($query)
    {
        parent::setKeysForSelectQuery($query);

        return $query->where($this->tenantColumn(), $this->getAttribute($this->tenantColumn()));
    }

    /**
     * Read or write across organizations, deliberately and visibly.
     *
     * Call sites are restricted by an architecture test.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
