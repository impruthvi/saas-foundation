<?php

declare(strict_types=1);

use App\Actions\CreateOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Assignments do not outlive the organization that scoped them
|--------------------------------------------------------------------------
|
| The package's migration stub constrains `role_id` and `permission_id` and
| stops, so an organization can be deleted while its assignments stay. That is
| not a tidiness problem: keys auto-increment, so a later organization can be
| handed the same id and inherit grants nobody gave it. The migration adds the
| constraint (D29); these are the tests that keep it.
|
| The rows are written by hand because nothing assigns roles yet — that is the
| action work, one task later. What is under test here is the schema.
|
*/
/**
 * @return array{0: Organization, 1: User}
 */
function organizationHolding(string $table, string $pivot, string $catalog): array
{
    $user = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($user, 'Acme');

    DB::table($table)->insert([
        $pivot => DB::table($catalog)->value('id'),
        'model_type' => new User()->getMorphClass(),
        'model_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    return [$organization, $user];
}

it('deletes the assignments held in an organization when it is deleted', function (string $table, string $pivot, string $catalog): void {
    [$organization] = organizationHolding($table, $pivot, $catalog);

    $organization->delete();

    $this->assertDatabaseMissing($table, ['organization_id' => $organization->id]);
})->with([
    'roles' => ['model_has_roles', 'role_id', 'roles'],
    'permissions' => ['model_has_permissions', 'permission_id', 'permissions'],
]);

it('refuses an assignment scoped to an organization that does not exist', function (string $table, string $pivot, string $catalog): void {
    $user = User::factory()->create();

    DB::table($table)->insert([
        $pivot => DB::table($catalog)->value('id'),
        'model_type' => new User()->getMorphClass(),
        'model_id' => $user->id,
        'organization_id' => 99_999,
    ]);
})->with([
    'roles' => ['model_has_roles', 'role_id', 'roles'],
    'permissions' => ['model_has_permissions', 'permission_id', 'permissions'],
])->throws(QueryException::class);

it('deletes a customer-defined role with the organization that defined it', function (): void {
    $organization = resolve(CreateOrganization::class)->handle(User::factory()->create(), 'Acme');

    DB::table('roles')->insert([
        'organization_id' => $organization->id,
        'name' => 'archivist',
        'guard_name' => 'web',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $organization->delete();

    $this->assertDatabaseMissing('roles', ['name' => 'archivist']);
    $this->assertDatabaseHas('roles', ['name' => 'admin', 'organization_id' => null]);
});
