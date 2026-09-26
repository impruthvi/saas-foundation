<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * SQL can stay scoped while a stale permission team authorizes the wrong organization,
 * so the two values are compared directly.
 */
final class AuthorizationTeamGuard
{
    public static function install(): void
    {
        DB::listen(function (): void {
            self::assertTeamTracksTenant();
        });
    }

    /**
     * The permission map lives in the cache store, which the suite runs as array, so
     * this guards against that changing: RefreshDatabase rolls rows back under a cache
     * that does not know.
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
