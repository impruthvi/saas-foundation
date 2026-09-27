<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Invitation;

/**
 * Both the tenant scope and the retrieved guard stand down here. Eligibility stays with
 * the accepting action so refusals are not collapsed into not found.
 */
final readonly class InvitationRepository
{
    public function __construct(private TenantContext $tenant) {}

    /**
     * Missing, mistyped and rotated tokens are deliberately indistinguishable.
     */
    public function findByToken(string $token): ?Invitation
    {
        return $this->tenant->runWithoutTenant(fn (): ?Invitation => Invitation::query()
            ->withoutTenantScope()
            ->where('token_hash', Invitation::hashToken($token))
            ->first());
    }
}
