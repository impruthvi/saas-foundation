<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\ChangeOrganizationMemberRole;
use App\Actions\CreateOrganization;
use App\Actions\RemoveOrganizationMember;
use App\Actions\TransferOrganizationOwnership;
use App\Enums\MembershipRole;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantQueryGuard;

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

/**
 * Every membership whose rank and role assignment disagree.
 *
 * Read across organizations on purpose: the invariant is about the database as
 * a whole, and scoping the query to one organization would make it unable to
 * see the case it exists for.
 *
 * @return list<string>
 */
function projectionMismatches(): array
{
    return TenantQueryGuard::allowUnscoped(fn (): array => DB::table('memberships')
        ->leftJoin('model_has_roles', function ($join): void {
            $join->on('model_has_roles.model_id', '=', 'memberships.user_id')
                ->on('model_has_roles.organization_id', '=', 'memberships.organization_id');
        })
        ->leftJoin('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->whereColumn('memberships.role', '!=', DB::raw("coalesce(roles.name, '')"))
        ->selectRaw("memberships.id || ': rank ' || memberships.role || ', role ' || coalesce(roles.name, 'none') as detail")
        ->pluck('detail')
        ->all());
}

/**
 * Assignments with no membership behind them.
 *
 * The direction `projectionMismatches()` cannot see: it joins out from
 * memberships, so a grant whose membership is gone leaves nothing to join from.
 *
 * @return list<int>
 */
function orphanedAssignments(): array
{
    return TenantQueryGuard::allowUnscoped(fn (): array => DB::table('model_has_roles')
        ->leftJoin('memberships', function ($join): void {
            $join->on('memberships.user_id', '=', 'model_has_roles.model_id')
                ->on('memberships.organization_id', '=', 'model_has_roles.organization_id');
        })
        ->whereNull('memberships.id')
        ->pluck('model_has_roles.organization_id')
        ->all());
}

/**
 * Anyone holding more than one role in one organization.
 *
 * `syncRoles` prevents it, `assignRole` would not, and the mismatch join cannot
 * see it because one of the two rows always matches.
 *
 * @return list<int>
 */
function doubledAssignments(): array
{
    return TenantQueryGuard::allowUnscoped(fn (): array => DB::table('model_has_roles')
        ->select('model_id')
        ->groupBy('model_id', 'organization_id')
        ->havingRaw('count(*) > 1')
        ->pluck('model_id')
        ->all());
}

it('gives an organization owner the role their rank implies', function (): void {
    $owner = User::factory()->create();

    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');

    expect(assignmentsWithin($organization->id)->all())->toBe([$owner->id => 'admin'])
        ->and(projectionMismatches())->toBeEmpty();
});

it('gives an added member the role their rank implies', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');

    resolve(AddOrganizationMember::class)->handle($organization, $member);

    expect(assignmentsWithin($organization->id)->all())
        ->toBe([$owner->id => 'admin', $member->id => 'member'])
        ->and(projectionMismatches())->toBeEmpty();
});

it('leaves neither the membership nor the assignment when the write is rolled back', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');

    try {
        DB::transaction(function () use ($organization, $member): void {
            resolve(AddOrganizationMember::class)->handle($organization, $member);

            throw new RuntimeException('the rest of the request failed');
        });
    } catch (RuntimeException) {
        // The point is what survives it.
    }

    expect(assignmentsWithin($organization->id)->all())->toBe([$owner->id => 'admin'])
        ->and(projectionMismatches())->toBeEmpty();
});

it('moves the role with the rank when ownership is transferred', function (): void {
    $owner = User::factory()->create();
    $successor = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    resolve(AddOrganizationMember::class)->handle($organization, $successor);

    resolve(TransferOrganizationOwnership::class)->handle($organization, $successor);

    expect(assignmentsWithin($organization->id)->all())
        ->toBe([$owner->id => 'admin', $successor->id => 'admin'])
        ->and(projectionMismatches())->toBeEmpty();
});

it('leaves no assignment behind when a membership ends', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $member);

    expect(orphanedAssignments())->toBeEmpty();

    resolve(RemoveOrganizationMember::class)->handle($membership);

    expect(orphanedAssignments())->toBeEmpty()
        ->and(doubledAssignments())->toBeEmpty()
        ->and(projectionMismatches())->toBeEmpty();
});

it('never lets one person hold two roles in one organization', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = resolve(CreateOrganization::class)->handle($owner, 'Acme');
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $member);

    resolve(ChangeOrganizationMemberRole::class)->handle($membership, MembershipRole::Admin);

    expect(doubledAssignments())->toBeEmpty()
        ->and(projectionMismatches())->toBeEmpty();
});

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

    // Counting every assignment regardless of organization is the assertion
    // itself: the backfill must have written two rows and not a third. The
    // guard is stood down by name rather than by adding an organization_id to
    // the query, which would make the count unable to see a leak.
    $total = TenantQueryGuard::allowUnscoped(
        fn (): int => DB::table('model_has_roles')->count(),
    );

    expect(assignmentsWithin($acme->id))->toHaveCount(1)
        ->and(assignmentsWithin($other->id))->toHaveCount(1)
        ->and($total)->toBe(2);
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
