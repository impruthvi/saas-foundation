<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Operator;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Operator>
 */
final class OperatorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'granted_by' => null,
            'reason' => 'Support rota',
            'granted_at' => now(),
        ];
    }
}
