<?php

declare(strict_types=1);

use App\Enums\MembershipRole;
use App\Enums\OrganizationRole;
use App\Enums\Permission;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The catalog the enums describe is the catalog in the database
|--------------------------------------------------------------------------
|
| The migration seeds role and permission names literally, because a migration
| has to keep describing the database it built even after an enum is renamed.
| That is the right call and it buys a drift problem: two statements of the
| same catalog, either of which can move without the other.
|
| This is the seam that refuses to let them. It reads the rows and compares
| them with the enums in both directions, so a permission added to code and not
| to the database fails here, and so does a row nobody declares.
|
*/

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

it('names a role for every rank a membership can carry', function (MembershipRole $rank): void {
    expect(OrganizationRole::forRank($rank))->toBeInstanceOf(OrganizationRole::class);
})->with(MembershipRole::cases());

it('uses the same guard the application authenticates with', function (): void {
    $guards = DB::table('permissions')->pluck('guard_name')
        ->merge(DB::table('roles')->pluck('guard_name'))
        ->unique()
        ->values()
        ->all();

    expect($guards)->toBe([config('auth.defaults.guard')]);
});
