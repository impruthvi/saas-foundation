<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Contracts\TenantOwned;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Lets the boundary be asserted on its own terms rather than re-asserting Project.
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
