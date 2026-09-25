<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Enums\AuditSource;
use App\Models\AuditEvent;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
final class AuditEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'actor_id' => null,
            'impersonation_id' => null,
            'source' => AuditSource::System,
            'action' => AuditAction::MemberRemoved,
            'subject_type' => null,
            'subject_id' => null,
            'context' => [],
            'occurred_at' => now(),
        ];
    }
}
