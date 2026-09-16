<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Contracts\TenantOwned;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The first genuinely tenant-owned model (D21).
 *
 * Deliberately almost empty. Its job at M1 is to be the thing the tenant
 * boundary protects, so that the boundary has a consumer in the milestone that
 * writes it. M5 gives it the `projects` limit, the usage meter and the upgrade
 * prompt.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 *
 * @method static ProjectFactory factory($count = null, $state = [])
 * @method static Builder<static>|Project newModelQuery()
 * @method static Builder<static>|Project newQuery()
 * @method static Builder<static>|Project query()
 *
 * @mixin Model
 */
#[Fillable(['organization_id', 'name'])]
final class Project extends Model implements TenantOwned
{
    use BelongsToOrganization;

    /** @use HasFactory<ProjectFactory> */
    use HasFactory;
}
