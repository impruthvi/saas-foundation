<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FailedWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FailedWebhookEvent>
 */
final class FailedWebhookEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stripe_event_id' => 'evt_'.Str::lower(Str::random(24)),
            'type' => 'customer.subscription.updated',
            'stripe_customer_id' => 'cus_'.Str::lower(Str::random(24)),
            'payload' => fn (array $attributes): array => [
                'id' => $attributes['stripe_event_id'],
                'type' => $attributes['type'],
                'data' => [
                    'object' => ['customer' => $attributes['stripe_customer_id']],
                ],
            ],
            'reason' => 'OrganizationNotFound',
            'message' => fake()->sentence(),
            'created_at' => now(),
        ];
    }
}
