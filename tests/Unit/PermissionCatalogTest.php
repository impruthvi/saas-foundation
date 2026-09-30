<?php

declare(strict_types=1);

use App\Enums\MembershipRank;
use App\Enums\OrganizationRole;
use App\Enums\Permission;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantQueryGuard;

/**
 * @return list<string>
 */
function grantedTo(string $role): array
{
    return DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('roles.name', $role)
        ->pluck('permissions.name')
        ->all();
}

it('has a row for every permission the application names, and no others', function (): void {
    $stored = DB::table('permissions')->pluck('name')->sort()->values()->all();

    expect($stored)->toBe(collect(Permission::names())->sort()->values()->all());
});

it('has a global row for every role the application names, and no others', function (): void {
    $stored = DB::table('roles')->pluck('name')->sort()->values()->all();

    expect($stored)->toBe(collect(OrganizationRole::names())->sort()->values()->all())
        ->and(DB::table('roles')->whereNotNull('organization_id')->count())->toBe(0);
});

it('grants exactly the permissions each role declares', function (OrganizationRole $role): void {
    $granted = DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('roles.name', $role->value)
        ->pluck('permissions.name')
        ->sort()
        ->values()
        ->all();

    $declared = collect($role->permissions())
        ->map(fn (Permission $permission): string => $permission->value)
        ->sort()
        ->values()
        ->all();

    expect($granted)->toBe($declared);
})->with(OrganizationRole::cases());

it('gives every role something to do', function (OrganizationRole $role): void {
    expect($role->permissions())->not->toBeEmpty();
})->with(OrganizationRole::cases());

it('names a role for every rank a membership can carry', function (MembershipRank $rank): void {
    expect(OrganizationRole::forRank($rank))->toBeInstanceOf(OrganizationRole::class);
})->with(MembershipRank::cases());

it('adds and removes the projects permission with its own migration', function (): void {
    // Named rather than counted, so a later migration cannot turn the rollback into a
    // no-op that passes for the wrong reason.
    $migration = 'database/migrations/2026_09_21_130000_add_manage_projects_permission.php';
    $permission = Permission::ManageProjects->value;

    // Withdrawing a permission clears it from every organization that granted it, so
    // the rollback crosses tenants on purpose.
    TenantQueryGuard::allowUnscoped(
        fn () => Artisan::call('migrate:rollback', ['--path' => $migration, '--force' => true]),
    );

    expect(DB::table('permissions')->where('name', $permission)->exists())->toBeFalse()
        ->and(grantedTo('admin'))->not->toContain($permission)
        ->and(grantedTo('member'))->not->toContain($permission);

    Artisan::call('migrate', ['--path' => $migration, '--force' => true]);

    expect(DB::table('permissions')->where('name', $permission)->count())->toBeOne()
        ->and(grantedTo('admin'))->toContain($permission)
        ->and(grantedTo('member'))->toContain($permission);
});

it('uses the same guard the application authenticates with', function (): void {
    $guards = DB::table('permissions')->pluck('guard_name')
        ->merge(DB::table('roles')->pluck('guard_name'))
        ->unique()
        ->values()
        ->all();

    expect($guards)->toBe([config('auth.defaults.guard')]);
});
