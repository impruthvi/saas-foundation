<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Exceptions\TenantContextMissing;
use App\Models\Organization;
use Closure;
use Illuminate\Support\Facades\Context;
use Spatie\Permission\PermissionRegistrar;

/**
 * Clears the organization from context, queue payloads and the permission registrar
 * together, so a long-lived worker cannot authorize against the previous job's
 * organization.
 */
final class TenantContext
{
    /**
     * Mirrored into queue payloads.
     */
    public const string KEY = 'tenant.organization_id';

    private ?int $organizationId = null;

    private ?Organization $organization = null;

    public function id(): ?int
    {
        return $this->organizationId;
    }

    public function idOrFail(): int
    {
        return $this->organizationId ?? throw TenantContextMissing::forModel(self::class);
    }

    public function hasTenant(): bool
    {
        return $this->organizationId !== null;
    }

    public function setId(int $organizationId): void
    {
        if ($organizationId !== $this->organizationId) {
            $this->organization = null;
        }

        $this->organizationId = $organizationId;

        Context::add(self::KEY, $organizationId);

        $this->resolveAuthorization()->setPermissionsTeamId($organizationId);
    }

    public function set(Organization $organization): void
    {
        $this->setId($organization->id);

        $this->organization = $organization;
    }

    public function current(): ?Organization
    {
        if ($this->organizationId === null) {
            return null;
        }

        return $this->organization ??= Organization::query()->find($this->organizationId);
    }

    public function forget(): void
    {
        $this->organizationId = null;
        $this->organization = null;

        Context::forget(self::KEY);

        $this->resolveAuthorization()->setPermissionsTeamId(null);
    }

    /**
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
     * Resolved at call time, not injected: the package binds the singleton in
     * packageBooted(), so early auto-wiring would create a second, unshared registrar.
     */
    private function resolveAuthorization(): PermissionRegistrar
    {
        return resolve(PermissionRegistrar::class);
    }
}
