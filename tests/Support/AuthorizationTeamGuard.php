<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fails when the permission registrar's team differs from the resolved tenant.
 *
 * SQL can remain scoped while a stale permission team authorizes the wrong
 * organization, so every query also checks the two values directly.
 */
final class AuthorizationTeamGuard
{
    /**
     * Install the listener for the current test.
     */
    public static function install(): void
    {
        DB::listen(function (): void {
            self::assertTeamTracksTenant();
        });
    }

    /**
     * Drop anything the package cached for a previous test.
     *
     * The permission map is cached in the cache store, which the suite runs as
     * `array` — so it is already per-test, and this is a guard against the day
     * that changes rather than a fix for something failing now. It matters
     * because `RefreshDatabase` rolls the rows out from under a cache that has
     * no idea it happened, and the resulting failures look like flake.
     */
    public static function flush(): void
    {
        $registrar = resolve(PermissionRegistrar::class);

        $registrar->forgetCachedPermissions();
        $registrar->setPermissionsTeamId(null);
    }

    private static function assertTeamTracksTenant(): void
    {
        $tenant = resolve(TenantContext::class)->id();
        $team = resolve(PermissionRegistrar::class)->getPermissionsTeamId();

        throw_if(
            $team !== $tenant,
            RuntimeException::class,
            sprintf(
                'Authorization would answer for organization [%s] while the resolved tenant is [%s]. '
                .'TenantContext is the only writer of the permissions team id; something else set it, '
                .'or a tenant was resolved by a path that does not go through TenantContext.',
                $team === null ? 'none' : (string) $team,
                $tenant === null ? 'none' : (string) $tenant,
            ),
        );
    }
}
