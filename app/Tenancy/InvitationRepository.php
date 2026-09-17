<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Invitation;

/**
 * The second query that cannot be scoped, in the one place allowed to make it.
 *
 * "Which invitation does this token name" is asked by a stranger. They are
 * unauthenticated, or authenticated with their *own* organization resolved, and
 * in neither case is it the organization the invitation belongs to. So the
 * question arrives before the tenant it concerns is known — the same shape as
 * D22's membership lookup, and D27 gives it the same answer: one named, tested
 * door, with `tests/Unit/TenantScopingTest.php` failing the build if a second
 * one appears anywhere else.
 *
 * Both halves of the boundary have to stand down, which is why the work runs
 * inside `runWithoutTenant()` rather than only reaching for
 * `withoutTenantScope()`:
 *
 *   withoutTenantScope()  ─► lets the SELECT run unconstrained
 *   runWithoutTenant()    ─► stops the `retrieved` guard raising CrossTenantAccess
 *                            when the row turns out to belong to someone else
 *
 * Callers get the row and nothing else. Deciding whether it may be taken is the
 * accepting action's job, because the answer is one of eight distinct refusals
 * and a repository that returned null would collapse them all into "not found".
 */
final readonly class InvitationRepository
{
    public function __construct(private TenantContext $tenant) {}

    /**
     * The invitation a token names, whatever organization it belongs to.
     *
     * Null covers three cases that are indistinguishable from outside and must
     * stay that way: a token that was rotated by a resend, one that was mistyped,
     * and one that never existed. A caller renders all three as "no longer
     * valid" — never as "expired", which would be a claim about a row that is
     * not there.
     */
    public function findByToken(string $token): ?Invitation
    {
        return $this->tenant->runWithoutTenant(fn (): ?Invitation => Invitation::query()
            ->withoutTenantScope()
            ->where('token_hash', Invitation::hashToken($token))
            ->first());
    }
}
