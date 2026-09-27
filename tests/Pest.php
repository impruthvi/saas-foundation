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
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Spatie\Permission\Models\Permission as StoredPermission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\AuthorizationTeamGuard;
use Tests\Support\TenantQueryGuard;
use Tests\TestCase;

$strictBoot = function (): void {
    // Automatic relationship autoloading runs before the lazy-loading check in
    // Model::getRelationValue(), so under test it would stop preventLazyLoading ever
    // firing for models from a collection. Disabled only here, as
    // Factory::createChildren() also does.
    Model::automaticallyEagerLoadRelationships(false);

    TenantQueryGuard::flush();

    // These tenant-owned pivot tables have no application models to discover.
    TenantQueryGuard::register('model_has_roles');
    TenantQueryGuard::register('model_has_permissions');

    // The entitlement package keys on the owner and reads its ledgers by a primary key
    // hashed from it, so both columns count as narrowing. Work that spans owners says
    // so through acrossEveryOwner(). cashier_entitlement_audit_runs records runs, not
    // anything an owner holds.
    foreach ([
        'cashier_entitlement_states',
        'cashier_entitlement_usage_counters',
        'cashier_entitlement_usage_events',
        'cashier_entitlement_receipts',
        'cashier_entitlement_billing_periods',
        'cashier_entitlement_overrides',
        'cashier_entitlement_driver_bindings',
    ] as $ownerKeyedTable) {
        TenantQueryGuard::register($ownerKeyedTable, ['owner_id', 'id']);
    }

    TenantQueryGuard::install();

    // A stale permissions team can cross tenants without issuing unscoped SQL.
    AuthorizationTeamGuard::flush();
    AuthorizationTeamGuard::install();

    Str::createRandomStringsNormally();
    Str::createUuidsNormally();
    Http::preventStrayRequests();
    Sleep::fake();
};

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () use ($strictBoot): void {
        $strictBoot();

        $this->freezeTime();
    })
    ->in('Browser', 'Feature', 'Unit');

// The entitlement refresh refuses to run inside a transaction, which rules out
// RefreshDatabase; truncating rules out :memory:, whose schema does not survive the
// reconnect. Truncation empties the RBAC catalog, so it is rebuilt per test.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->beforeEach(function () use ($strictBoot): void {
        if (DB::connection()->getConfig('database') === ':memory:') {
            $this->markTestSkipped('Committed tests need a database that survives reconnection; :memory: does not.');
        }

        $strictBoot();

        seedAuthorizationCatalog();

        $this->freezeTime();
    })
    ->in('Committed');

// Forked processes read through their own connections and cannot see an open
// transaction, so rows are committed and tables truncated between tests.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->beforeEach(function (): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrency tests need PostgreSQL: every SQLite connection opens its own database.');
        }

        Model::automaticallyEagerLoadRelationships(false);

        seedAuthorizationCatalog();
    })
    ->in('Concurrency');

function seedAuthorizationCatalog(): void
{
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
}

expect()->extend('toBeOne', fn () => $this->toBe(1));

/**
 * Token lookup has no organization scope by design; one named helper keeps every such
 * read explicit.
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
 * hasPermissionTo() because the gate does not know package permission names; relations
 * are cleared because they cache per instance.
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
 * HasRoles detaches across all teams on delete, so its deletes carry no
 * organization_id. That crossing is intended, so it gets a named door rather than a
 * blanket exemption.
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
 * The package keys on the owner, so asserting that nobody anywhere was charged has to
 * look past the tenant boundary. That crossing is a test's, never the application's.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $work
 * @return TReturn
 */
function acrossEveryOwner(Closure $work): mixed
{
    return TenantQueryGuard::allowUnscoped($work);
}

function findInvitation(string $token): ?Invitation
{
    return throughTheAuditedDoor(
        fn (): ?Invitation => resolve(InvitationRepository::class)->findByToken($token),
    );
}

function issueInvitation(Organization $organization, string $email, ?User $by = null): string
{
    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): string => resolve(InviteOrganizationMember::class)
            ->handle($organization, $email, MembershipRole::Member, $by)['token'],
    );
}

function organizationEntitlementOwner(Organization $organization): OwnerReference
{
    return resolve(OwnerLocator::class)->reference($organization);
}

/**
 * Goes through the request, claim and complete handshake, which decides whether the
 * resolver will trust the row.
 */
function applyAllowanceDecision(
    OwnerReference $owner,
    BillingDecision $decision,
    DateTimeImmutable $observedAt,
    ?string $catalogVersion = null,
): void {
    $store = resolve(NativeStateStore::class);

    expect($store->request($owner, $observedAt))->toBeTrue();

    $claim = $store->claim($owner, $observedAt);

    expect($claim)->not->toBeNull()
        ->and($store->complete(
            $claim,
            $decision,
            $catalogVersion ?? resolve(PriceCatalog::class)->version,
            $observedAt,
            $observedAt,
        ))->toBeTrue();
}

/**
 * @return array{0: Organization, 1: User}
 */
function organizationOwnedBySomeone(string $name = 'Acme'): array
{
    $owner = User::factory()->create();

    return [resolve(CreateOrganization::class)->handle($owner, $name), $owner];
}
