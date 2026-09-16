<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Contracts\TenantOwned;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * A minimal tenant-owned model, so the boundary can be asserted on its own terms.
 *
 * It exists because the boundary is written before the models it protects, and
 * because a test that pins the rule to `Project` would be re-asserting `Project`
 * rather than the rule.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 */
#[Table(name: 'tenant_owned_fixtures')]
#[WithoutTimestamps]
final class TenantOwnedFixture extends Model implements TenantOwned
{
    protected $guarded = [];

    public function tenantColumn(): string
    {
        return 'organization_id';
    }

    protected static function booted(): void
    {
        self::addGlobalScope(new TenantScope());
    }
}
