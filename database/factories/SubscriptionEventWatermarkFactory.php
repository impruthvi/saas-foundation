<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\SubscriptionEventWatermark;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionEventWatermark>
 */
final class SubscriptionEventWatermarkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'stripe_id' => 'sub_'.Str::lower(Str::random(24)),
            'event_created_at' => fake()->unixTime(),
        ];
    }
}
