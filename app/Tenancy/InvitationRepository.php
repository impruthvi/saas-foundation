<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Invitation;

/**
 * Resolves an invitation before its organization is known.
 *
 * Both the tenant scope and retrieved guard must stand down for this lookup.
 * Eligibility remains the accepting action's responsibility so distinct
 * refusal reasons are not collapsed into "not found".
 */
final readonly class InvitationRepository
{
    public function __construct(private TenantContext $tenant) {}

    /**
     * A missing, mistyped, or rotated token is deliberately indistinguishable.
     */
    public function findByToken(string $token): ?Invitation
    {
        return $this->tenant->runWithoutTenant(fn (): ?Invitation => Invitation::query()
            ->withoutTenantScope()
            ->where('token_hash', Invitation::hashToken($token))
            ->first());
    }
}
