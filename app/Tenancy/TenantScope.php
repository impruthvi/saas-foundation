<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Contracts\TenantOwned;
use App\Exceptions\TenantContextMissing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the resolved organization.
 *
 * Missing context raises rather than falling back to an unconstrained query.
 */
/**
 * @implements Scope<Model>
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! $model instanceof TenantOwned) {
            return;
        }

        $tenant = resolve(TenantContext::class);

        if (! $tenant->hasTenant()) {
            throw TenantContextMissing::forModel($model::class);
        }

        $builder->where(
            $model->qualifyColumn($model->tenantColumn()),
            $tenant->idOrFail(),
        );
    }
}
