<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Exceptions\TenantContextMissing;
use App\Models\Organization;
use Closure;
use Illuminate\Support\Facades\Context;
use Spatie\Permission\PermissionRegistrar;

/**
 * Keeps the resolved organization synchronized with queue context and the
 * permission registrar. Clearing all three stores prevents a long-lived worker
 * from authorizing against the previous job's organization.
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

        $this->resolveAuthorization()->setPermissionsTeamId($organizationId);
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

        $this->resolveAuthorization()->setPermissionsTeamId(null);
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
     * organization it is acting for and must not inherit one.
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
     * does this user belong to".
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

    /**
     * The registrar holding the team every role assignment is read against.
     *
     * Resolved here rather than injected. The package binds it as a singleton
     * from its `packageBooted()`, so a constructor argument would be filled by
     * whatever the container could auto-wire if anything resolved this class
     * first — a second, unshared registrar, written to here and never read by
     * the package. Asking for it at call time is always after boot.
     */
    private function resolveAuthorization(): PermissionRegistrar
    {
        return resolve(PermissionRegistrar::class);
    }
}
