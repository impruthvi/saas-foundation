<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvitationStatus;
use App\Enums\MembershipRank;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
final class InvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'role' => MembershipRank::Member,
            'token_hash' => Invitation::hashToken(Str::random(48)),
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->addDays(7),
            'invited_by_user_id' => User::factory(),
        ];
    }
}
