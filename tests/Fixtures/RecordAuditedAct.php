<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Actions\RecordAuditEvent;
use App\Enums\AuditAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * An audited act performed on the queue, with no actor of its own.
 *
 * Whoever it records as acting came from the payload.
 */
final class RecordAuditedAct implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(private readonly int $organizationId, private readonly string $label) {}

    public function handle(RecordAuditEvent $audit): void
    {
        $audit->handle($this->organizationId, AuditAction::MemberRemoved, context: ['label' => $this->label]);
    }
}
