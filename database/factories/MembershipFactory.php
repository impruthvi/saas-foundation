<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MembershipRank;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
final class MembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => MembershipRank::Member,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MembershipStatus::Suspended,
        ]);
    }
}
