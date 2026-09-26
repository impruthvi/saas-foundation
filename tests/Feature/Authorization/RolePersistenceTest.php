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

function replayTheRbacMigration(): void
{
    // Named rather than counted: rolling back a fixed number of steps would replay
    // whichever migration is last and pass for the wrong reason.
    $migration = 'database/migrations/2026_09_18_131132_create_permission_tables.php';

    Artisan::call('migrate:rollback', ['--path' => $migration, '--force' => true]);
    Artisan::call('migrate', ['--path' => $migration, '--force' => true]);
}

/**
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
 * Reads across organizations on purpose: scoping to one would hide the case this exists
 * for.
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
 * The direction projectionMismatches() cannot see: a grant whose membership is gone
 * leaves nothing to join from.
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
 * syncRoles prevents this and assignRole would not; the mismatch join cannot see it
 * because one of the two rows always matches.
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
        // What matters is what survives the rollback.
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

    // Counting every assignment is the assertion: the backfill must write two rows, not
    // three. The guard is stood down by name rather than scoping the query, which would
    // hide a leak.
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
