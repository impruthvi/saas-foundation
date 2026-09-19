<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\User;

/**
 * Gives every newly registered user a personal organization, avoiding a second
 * billing and entitlement path for solo users.
 */
final readonly class CreatePersonalOrganization
{
    public function __construct(private CreateOrganization $organizations) {}

    public function handle(User $user): Organization
    {
        return $this->organizations->handle(
            owner: $user,
            name: __(":name's Workspace", ['name' => $user->name]),
            personal: true,
        );
    }
}
