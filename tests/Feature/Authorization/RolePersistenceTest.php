<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\CreateOrganization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The backfill
|--------------------------------------------------------------------------
|
| A role assignment is a projection of a membership's rank (D31). That holds
| for memberships written after the package arrives because one action writes
| both; it holds for the ones already in the database only because the
| migration goes back and writes them.
|
| Without it, an application upgraded in place keeps every membership and
| loses every ability attached to it — each administrator and each owner drops
| to zero permissions on deploy. So the test rolls the migration back, which
| drops the assignments, and runs it forward against memberships that already
| exist. That is the upgrade, performed.
|
*/

/**
 * Re-run the migration that owns the RBAC tables, the way a deploy does.
 */
function replayTheRbacMigration(): void
{
    Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
    Artisan::call('migrate', ['--force' => true]);
}

/**
 * Every assignment in one organization, as user id => role name.
 *
 * @return Collection<int, string>
 */
function assignmentsWithin(int $organizationId): Collection
{
    return DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('model_has_roles.organization_id', $organizationId)
        ->pluck('roles.name', 'model_has_roles.model_id');
}

it('gives memberships that already existed the role their rank implies', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    resolve(AddOrganizationMember::class)->handle($organization, $member);

    replayTheRbacMigration();

    $assignments = assignmentsWithin($organization->id);

    expect($assignments)->toHaveCount(2)
        ->and($assignments[$owner->id])->toBe('admin')
        ->and($assignments[$member->id])->toBe('member');
});

it('writes the model type the package will write for itself', function (): void {
    $user = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($user, 'Acme');

    replayTheRbacMigration();

    $modelType = DB::table('model_has_roles')
        ->where('organization_id', $organization->id)
        ->value('model_type');

    expect($modelType)->toBe(new User()->getMorphClass());
});

it('keeps each organization assignments to itself', function (): void {
    $user = User::factory()->create();

    $acme = resolve(CreateOrganization::class)->handle($user, 'Acme');
    $other = resolve(CreateOrganization::class)->handle($user, 'Other');

    replayTheRbacMigration();

    expect(assignmentsWithin($acme->id))->toHaveCount(1)
        ->and(assignmentsWithin($other->id))->toHaveCount(1)
        ->and(DB::table('model_has_roles')->count())->toBe(2);
});

it('skips a membership whose rank names no role rather than guessing one', function (): void {
    $user = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($user, 'Acme');

    DB::table('memberships')
        ->where('organization_id', $organization->id)
        ->update(['role' => 'archivist']);

    replayTheRbacMigration();

    expect(assignmentsWithin($organization->id))->toBeEmpty();
});
