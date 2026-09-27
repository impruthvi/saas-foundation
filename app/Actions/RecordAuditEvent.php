<?php

declare(strict_types=1);

namespace App\Actions;

use App\Audit\AuditActor;
use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Written under the organization the act concerns, not the resolved one: accepting runs
 * with the acceptor's own organization resolved. Called inside the acting transaction
 * so a rolled-back act leaves no record.
 */
final readonly class RecordAuditEvent
{
    public function __construct(private TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function handle(int $organizationId, AuditAction $action, ?Model $subject = null, array $context = []): AuditEvent
    {
        $actor = AuditActor::current();

        return $this->tenant->runForId($organizationId, fn (): AuditEvent => AuditEvent::query()->create([
            'organization_id' => $organizationId,
            'actor_id' => $actor->userId,
            'impersonation_id' => $actor->impersonationId,
            'source' => $actor->source,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'context' => $context,
            'occurred_at' => now(),
        ]));
    }
}
