<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Impersonation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Impersonation>
 */
final class ImpersonationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operator_id' => User::factory(),
            'user_id' => User::factory(),
            'reason' => fake()->sentence(),
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ];
    }
}
