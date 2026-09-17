<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\User;

/**
 * Gives a newly registered user the organization they already implicitly have.
 *
 * D1's consequence: there are no personal subscriptions, so a solo user is an
 * organization of one rather than a special case threaded through billing and
 * entitlements. The workspace switcher is hidden for them, not absent.
 *
 * "Workspace" is UI copy only; the domain word stays organization (CONTEXT.md).
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
