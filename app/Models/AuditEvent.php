<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Contracts\TenantOwned;
use App\Enums\AuditAction;
use App\Enums\AuditSource;
use Carbon\CarbonImmutable;
use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One intentional, attributed act inside an organization.
 *
 * Append-only: a row that could be edited or removed through the application
 * would not be evidence of anything.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $actor_id
 * @property int|null $impersonation_id
 * @property AuditSource $source
 * @property AuditAction $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed> $context
 * @property CarbonImmutable $occurred_at
 * @property-read Organization $organization
 *
 * @method static AuditEventFactory factory($count = null, $state = [])
 * @method static Builder<static>|AuditEvent newModelQuery()
 * @method static Builder<static>|AuditEvent newQuery()
 * @method static Builder<static>|AuditEvent query()
 *
 * @mixin Model
 */
#[Fillable([
    'organization_id', 'actor_id', 'impersonation_id', 'source', 'action',
    'subject_type', 'subject_id', 'context', 'occurred_at',
])]
#[WithoutTimestamps]
final class AuditEvent extends Model implements TenantOwned
{
    use BelongsToOrganization;

    /** @use HasFactory<AuditEventFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Audit events cannot be changed.'));
        self::deleting(static fn (): never => throw new LogicException('Audit events cannot be deleted.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => AuditSource::class,
            'action' => AuditAction::class,
            'subject_id' => 'integer',
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
