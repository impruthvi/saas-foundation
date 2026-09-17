<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvitationStatus;
use App\Enums\MembershipRole;
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
            'role' => MembershipRole::Member,
            'token_hash' => Invitation::hashToken(Str::random(48)),
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->addDays(7),
            'invited_by_user_id' => User::factory(),
        ];
    }

    /**
     * An invitation whose token the test needs to hold.
     *
     * The plaintext exists only at the moment it is minted, so a test that means
     * to click the link has to say so when the row is built.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn (array $attributes): array => [
            'token_hash' => Invitation::hashToken($token),
        ]);
    }

    /**
     * Addressed to a specific person, which most tests care about.
     *
     * Not named `for()`: that is `Factory::for()`, which declares a relationship.
     */
    public function addressedTo(string $email): static
    {
        return $this->state(fn (array $attributes): array => [
            'email' => Str::lower($email),
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => MembershipRole::Admin,
        ]);
    }

    /**
     * Still open, but the clock ran out. Status stays Pending on purpose:
     * expiry is derived from the timestamp and is never written down.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvitationStatus::Revoked,
            'revoked_at' => now(),
            'revoked_by_user_id' => User::factory(),
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvitationStatus::Declined,
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvitationStatus::Accepted,
            'accepted_at' => now(),
            'accepted_by_user_id' => User::factory(),
        ]);
    }
}
