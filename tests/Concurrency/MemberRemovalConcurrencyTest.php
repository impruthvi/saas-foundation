<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\RemoveOrganizationMember;
use App\Enums\MembershipRank;
use App\Enums\MembershipStatus;
use App\Exceptions\Memberships\LastAdministrator;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Tests\Support\Contenders;
use Tests\Support\Outcome;

/**
 * Loaded inside the fork: a model carried across it would hold the parent's connection.
 */
function attemptRemoval(int $organizationId, int $membershipId): Outcome
{
    $membership = resolve(TenantContext::class)->runForId(
        $organizationId,
        fn (): Membership => Membership::query()->findOrFail($membershipId),
    );

    try {
        resolve(RemoveOrganizationMember::class)->handle($membership);

        return Outcome::Succeeded;
    } catch (LastAdministrator) {
        return Outcome::Refused;
    }
}

function activeAdministratorCount(Organization $organization): int
{
    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): int => Membership::query()->administrators()->count(),
    );
}

it('never lets two simultaneous removals strand an organization', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();

    // Only an active administrator can strand an organization, so the owner's
    // administrator membership is stood down to leave exactly two.
    resolve(TenantContext::class)->runFor($organization, function () use ($organization, $owner): void {
        Membership::query()
            ->where('user_id', $owner->id)
            ->where('organization_id', $organization->id)
            ->first()
            ?->forceFill(['status' => MembershipStatus::Suspended])
            ->save();
    });

    $first = resolve(AddOrganizationMember::class)
        ->handle($organization, User::factory()->create(), MembershipRank::Admin);
    $second = resolve(AddOrganizationMember::class)
        ->handle($organization, User::factory()->create(), MembershipRank::Admin);

    expect(activeAdministratorCount($organization))->toBe(2);

    $outcomes = Contenders::race([
        fn (): Outcome => attemptRemoval($organization->id, $first->id),
        fn (): Outcome => attemptRemoval($organization->id, $second->id),
    ]);

    $outcomeNames = array_map(fn (Outcome $outcome): string => $outcome->name, $outcomes);
    sort($outcomeNames);

    expect($outcomeNames)->toBe([
        Outcome::Refused->name,
        Outcome::Succeeded->name,
    ])
        ->and(activeAdministratorCount($organization))->toBe(1);
});
