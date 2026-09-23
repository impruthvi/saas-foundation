<?php

declare(strict_types=1);

use App\Actions\CreateOrganization;
use App\Actions\InviteOrganizationMember;
use App\Enums\MembershipRole;
use App\Enums\OrganizationRole;
use App\Enums\Permission;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\InvitationRepository;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as StoredPermission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\AuthorizationTeamGuard;
use Tests\Support\TenantQueryGuard;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Automatic relationship autoloading runs *before* the lazy-loading check in
        // Model::getRelationValue(), and returns early when it succeeds. Left enabled
        // under test, it silently satisfies every relation accessed on a model that came
        // from a collection, so ShouldBeStrict's preventLazyLoading never fires for the
        // one case it exists to catch. Disabled here, and only here: the convenience is
        // real in development and production, but a guard that cannot fail is not a guard.
        // The framework makes the same call itself in Factory::createChildren().
        Model::automaticallyEagerLoadRelationships(false);

        // Assert the tenant boundary at the query layer for every test.
        TenantQueryGuard::flush();

        // These tenant-owned pivot tables have no application models to discover.
        TenantQueryGuard::register('model_has_roles');
        TenantQueryGuard::register('model_has_permissions');

        TenantQueryGuard::install();

        // A stale permissions team can cross tenants without issuing unscoped SQL.
        AuthorizationTeamGuard::flush();
        AuthorizationTeamGuard::install();

        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Browser', 'Feature', 'Unit');

/*
 * Concurrency tests need committed rows, because the processes they fork read
 * through their own connections and cannot see an open transaction. That rules
 * out RefreshDatabase, and with it the transaction the rest of the suite relies
 * on to undo itself, so the tables are truncated between tests instead.
 */
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->beforeEach(function (): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrency tests need PostgreSQL: every SQLite connection opens its own database.');
        }

        Model::automaticallyEagerLoadRelationships(false);

        $permissions = resolve(PermissionRegistrar::class);
        $permissions->setPermissionsTeamId(null);
        $permissions->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            StoredPermission::findOrCreate($permission->value);
        }

        foreach (OrganizationRole::cases() as $organizationRole) {
            Role::findOrCreate($organizationRole->value)
                ->syncPermissions(array_map(
                    fn (Permission $permission): string => $permission->value,
                    $organizationRole->permissions(),
                ));
        }

        $permissions->forgetCachedPermissions();
    })
    ->in('Concurrency');

expect()->extend('toBeOne', fn () => $this->toBe(1));

/**
 * Make a request or a read that resolves an invitation by its token.
 *
 * Token lookup intentionally has no organization scope. Keeping the guard
 * exemption in one named helper makes every cross-tenant read explicit.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $work
 * @return TReturn
 */
function throughTheAuditedDoor(Closure $work): mixed
{
    return TenantQueryGuard::allowUnscoped($work);
}

/**
 * What the user may do inside one organization, asked as a policy asks it.
 *
 * Laravel's gate does not know package permission names, so this uses
 * `hasPermissionTo()`. Relations are cleared because they cache per instance.
 */
function mayWithin(User $user, int $organizationId, Permission $permission): bool
{
    return resolve(TenantContext::class)->runForId(
        $organizationId,
        fn (): bool => $user->unsetRelation('roles')->unsetRelation('permissions')
            ->hasPermissionTo($permission->value),
    );
}

/**
 * Close an account, which revokes its roles in every organization at once.
 *
 * `HasRoles` detaches across all teams on delete, so the deletes it emits
 * carry no organization_id and the query guard flags them. That crossing is
 * intended — an account being closed should keep grants nowhere — so it gets a
 * door with a name rather than a blanket exemption, and the name says which
 * crossing is being allowed.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $work
 * @return TReturn
 */
function whileClosingAnAccount(Closure $work): mixed
{
    return TenantQueryGuard::allowUnscoped($work);
}

/**
 * Resolve an invitation the way a stranger's request does.
 */
function findInvitation(string $token): ?Invitation
{
    return throughTheAuditedDoor(
        fn (): ?Invitation => resolve(InvitationRepository::class)->findByToken($token),
    );
}

/**
 * Invite an address, and hand back the token the email would have carried.
 */
function issueInvitation(Organization $organization, string $email, ?User $by = null): string
{
    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): string => resolve(InviteOrganizationMember::class)
            ->handle($organization, $email, MembershipRole::Member, $by)['token'],
    );
}

/**
 * An organization and the user who owns it.
 *
 * @return array{0: Organization, 1: User}
 */
function organizationOwnedBySomeone(string $name = 'Acme'): array
{
    $owner = User::factory()->create();

    return [resolve(CreateOrganization::class)->handle($owner, $name), $owner];
}
