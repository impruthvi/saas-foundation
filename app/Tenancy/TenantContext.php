<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Exceptions\TenantContextMissing;
use App\Models\Organization;
use Closure;
use Illuminate\Support\Facades\Context;

/**
 * The resolved tenant for the current unit of work.
 *
 * Registered as a singleton, never read from a static, so a test, a queue worker
 * and a scheduled command each get their own. The identifier is mirrored into
 * `Illuminate\Log\Context`, which the framework dehydrates into every queue
 * payload and hydrates on `JobProcessing` before the payload is unserialized —
 * that is the whole propagation mechanism (D24).
 *
 *   set(id) ──► Context::add(tenant.organization_id)
 *                      │
 *                      ▼
 *            Queue::createPayloadUsing ──► payload
 *                      │
 *                      ▼
 *            JobProcessing ──► Context::hydrate ──► listener ──► set(id) | forget()
 */
final class TenantContext
{
    /**
     * The context key mirrored into queue payloads.
     */
    public const string KEY = 'tenant.organization_id';

    private ?int $organizationId = null;

    private ?Organization $organization = null;

    /**
     * The resolved organization's key, or null when no tenant is resolved.
     */
    public function id(): ?int
    {
        return $this->organizationId;
    }

    /**
     * The resolved organization's key, failing loudly when there is none.
     */
    public function idOrFail(): int
    {
        return $this->organizationId ?? throw TenantContextMissing::forModel(self::class);
    }

    public function hasTenant(): bool
    {
        return $this->organizationId !== null;
    }

    /**
     * Resolve a tenant for the current unit of work and mirror it into the context.
     */
    public function setId(int $organizationId): void
    {
        if ($organizationId !== $this->organizationId) {
            $this->organization = null;
        }

        $this->organizationId = $organizationId;

        Context::add(self::KEY, $organizationId);
    }

    /**
     * Resolve a tenant from an organization already in hand.
     */
    public function set(Organization $organization): void
    {
        $this->setId($organization->id);

        $this->organization = $organization;
    }

    /**
     * The resolved organization, loaded once per unit of work.
     *
     * Reads the organizations table, which is not tenant-owned, so no scope is
     * stood down to answer this.
     */
    public function current(): ?Organization
    {
        if ($this->organizationId === null) {
            return null;
        }

        return $this->organization ??= Organization::query()->find($this->organizationId);
    }

    /**
     * Drop the resolved tenant, including from the mirrored context.
     */
    public function forget(): void
    {
        $this->organizationId = null;
        $this->organization = null;

        Context::forget(self::KEY);
    }

    /**
     * Run the callback with the given organization resolved, then restore what was there.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runForId(int $organizationId, Closure $callback): mixed
    {
        $previous = $this->organizationId;

        $this->setId($organizationId);

        try {
            return $callback();
        } finally {
            $previous === null ? $this->forget() : $this->setId($previous);
        }
    }

    /**
     * Run the callback with the given organization resolved, then restore what was there.
     *
     * The entry point for work with no ambient tenant of its own: scheduled
     * commands, webhook processing, and anything else that knows which
     * organization it is acting for and must not inherit one (D24).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Organization $organization, Closure $callback): mixed
    {
        return $this->runForId($organization->id, $callback);
    }

    /**
     * Run the callback with no tenant resolved, then restore what was there.
     *
     * For work that is legitimately cross-tenant: console commands, the admin
     * console, and the one membership lookup that answers "which organizations
     * does this user belong to" (D22).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runWithoutTenant(Closure $callback): mixed
    {
        $previous = $this->organizationId;

        $this->forget();

        try {
            return $callback();
        } finally {
            if ($previous !== null) {
                $this->setId($previous);
            }
        }
    }
}
