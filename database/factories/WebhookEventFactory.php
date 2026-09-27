<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WebhookOutcome;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEvent>
 */
final class WebhookEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stripe_event_id' => 'evt_'.Str::lower(Str::random(24)),
            'type' => 'customer.subscription.updated',
            'stripe_customer_id' => 'cus_'.Str::lower(Str::random(24)),
            'stripe_object_id' => 'sub_'.Str::lower(Str::random(24)),
            'stripe_created_at' => now()->getTimestamp(),
            'outcome' => WebhookOutcome::Applied,
            'applied_at' => now(),
            'deliveries' => 1,
            'payload' => fn (array $attributes): array => [
                'id' => $attributes['stripe_event_id'],
                'type' => $attributes['type'],
                'data' => [
                    'object' => [
                        'id' => $attributes['stripe_object_id'],
                        'customer' => $attributes['stripe_customer_id'],
                    ],
                ],
            ],
            'first_received_at' => now(),
            'last_received_at' => now(),
        ];
    }

    public function unplaceable(): self
    {
        return $this->state([
            'outcome' => WebhookOutcome::Unplaceable,
            'outcome_reason' => 'OrganizationNotFound',
            'applied_at' => null,
        ]);
    }

    public function receivedDaysAgo(int $days): self
    {
        return $this->state([
            'first_received_at' => now()->subDays($days),
            'last_received_at' => now()->subDays($days),
        ]);
    }
}
