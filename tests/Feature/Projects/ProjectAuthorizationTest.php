<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Project;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** @return array{organization: string, name: string, idempotency_token: string} */
function projectPayload(string $organization, string $token = 'request-one'): array
{
    return [
        'organization' => $organization,
        'name' => 'New project',
        'idempotency_token' => $token,
    ];
}

it('allows owners administrators and members to create projects', function (MembershipRole|string $actor): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $user = $owner;

    if ($actor instanceof MembershipRole) {
        $user = User::factory()->create();
        resolve(AddOrganizationMember::class)->handle($organization, $user, $actor);
    }

    $this->actingAs($user)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->post(route('projects.store'), projectPayload($organization->slug))
        ->assertStatus(303);

    expect(resolve(TenantContext::class)->runFor($organization, fn (): int => Project::query()->count()))->toBe(1);
})->with([
    'owner' => ['owner'],
    'administrator' => [MembershipRole::Admin],
    'member' => [MembershipRole::Member],
]);

it('refuses a stale project form after the active organization changes', function (): void {
    [$first, $owner] = organizationOwnedBySomeone('First');
    [$second] = organizationOwnedBySomeone('Second');
    resolve(AddOrganizationMember::class)->handle($second, $owner);

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $second->id])
        ->post(route('projects.store'), projectPayload($first->slug))
        ->assertConflict()
        ->assertSeeText('This organization changed elsewhere.');

    expect(DB::table('projects')->where('organization_id', $second->id)->count())->toBe(0);
});

it('refuses suspended members and outsiders', function (string $actor): void {
    [$organization] = organizationOwnedBySomeone();
    $user = User::factory()->create();

    if ($actor === 'suspended') {
        $membership = resolve(AddOrganizationMember::class)->handle($organization, $user, MembershipRole::Admin);
        $membership->forceFill(['status' => MembershipStatus::Suspended])->save();
    }

    $this->actingAs($user)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->post(route('projects.store'), projectPayload($organization->slug))
        ->assertForbidden();

    expect(DB::table('projects')->where('organization_id', $organization->id)->count())->toBe(0);
})->with(['suspended', 'outsider']);

it('requires a valid name organization and idempotency token', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->postJson(route('projects.store'), [
            'organization' => $organization->slug,
            'name' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'idempotency_token']);

    expect(DB::table('projects')->where('organization_id', $organization->id)->count())->toBe(0);
});
