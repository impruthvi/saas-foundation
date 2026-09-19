<?php

declare(strict_types=1);

use App\Actions\CreateOrganization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantQueryGuard;

/*
|--------------------------------------------------------------------------
| Assignments do not outlive the organization that scoped them
|--------------------------------------------------------------------------
|
| The package's migration stub constrains `role_id` and `permission_id` and
| stops, so an organization could be deleted while its assignments stayed. That
| is not a tidiness problem: keys auto-increment, so a later organization can
| be handed the same id and inherit grants nobody gave it. The migration adds
| the constraint (D29); these are the tests that keep it.
|
| The role rows here are the real ones — creating an organization now writes an
| assignment for its owner (D31). Direct permission rows are still written by
| hand, because the application grants permissions through roles and never
| attaches one to a person; the foreign key is worth holding anyway, since the
| column exists and the package would use it.
|
*/

it('deletes the role assignments held in an organization when it is deleted', function (): void {
    $organization = resolve(CreateOrganization::class)->handle(User::factory()->create(), 'Acme');

    expect(DB::table('model_has_roles')->where('organization_id', $organization->id)->count())->toBe(1);

    $organization->delete();

    $this->assertDatabaseMissing('model_has_roles', ['organization_id' => $organization->id]);
});

it('deletes the direct permission assignments held in an organization when it is deleted', function (): void {
    $user = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($user, 'Acme');

    DB::table('model_has_permissions')->insert([
        'permission_id' => DB::table('permissions')->value('id'),
        'model_type' => new User()->getMorphClass(),
        'model_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    $organization->delete();

    $this->assertDatabaseMissing('model_has_permissions', ['organization_id' => $organization->id]);
});

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

it('leaves one organization assignments alone when another is deleted', function (): void {
    $user = User::factory()->create();

    $kept = resolve(CreateOrganization::class)->handle($user, 'Kept');
    $dropped = resolve(CreateOrganization::class)->handle($user, 'Dropped');

    $dropped->delete();

    $surviving = TenantQueryGuard::allowUnscoped(
        fn (): array => DB::table('model_has_roles')->pluck('organization_id')->all(),
    );

    expect($surviving)->toBe([$kept->id]);
});
