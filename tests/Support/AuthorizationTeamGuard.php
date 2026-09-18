<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fails any test in which `can()` would answer for the wrong organization.
 *
 * The companion to `TenantQueryGuard`, and the reason there has to be one.
 * That guard proves the tenant boundary by watching SQL (D20), which works
 * because a query that escapes the boundary is a query. The authorization
 * boundary has no such tell: `spatie/laravel-permission` keeps its active team
 * on a registrar, so a stale one answers `can()` for an organization nobody
 * resolved while every query it makes stays correctly scoped. Nothing wrong
 * reaches the database, and `TenantQueryGuard` sees nothing at all.
 *
 * D29's invariant is therefore asserted against the state itself:
 *
 *   PermissionRegistrar::getPermissionsTeamId() === TenantContext::id()
 *
 * at every point both are observable, null included. `TenantContext` is the
 * only writer of either (D29), so any disagreement is a defect rather than a
 * case to configure around — which is why there is no way past this guard.
 *
 * A query is the checkpoint, for the same reason `TenantQueryGuard` uses one:
 * it is the moment the application acts on what it believes the tenant to be,
 * and it makes every test in the suite an authorization test rather than the
 * handful written with roles in mind.
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
                .'TenantContext is the only writer of the permissions team id (D29); something else set it, '
                .'or a tenant was resolved by a path that does not go through TenantContext.',
                $team === null ? 'none' : (string) $team,
                $tenant === null ? 'none' : (string) $tenant,
            ),
        );
    }
}
