<?php

declare(strict_types=1);

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use App\Tenancy\TenantContext;

it('answers which organizations a user belongs to before any is resolved', function (): void {
    $user = User::factory()->create();
    $personal = Organization::factory()->personal()->create(['name' => 'Personal', 'owner_id' => $user->id]);
    $shared = Organization::factory()->create(['name' => 'Acme']);
    Organization::factory()->create(['name' => 'Somebody else']);

    Membership::factory()->for($personal)->for($user)->create();
    Membership::factory()->for($shared)->for($user)->create();

    $organizations = resolve(MembershipRepository::class)->organizationsFor($user);

    expect($organizations->pluck('name')->all())->toBe(['Personal', 'Acme']);
});

it('leaves suspended memberships out', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->for($organization)->for($user)->suspended()->create();

    expect(resolve(MembershipRepository::class)->organizationsFor($user))->toBeEmpty();
});

it('restores the resolved tenant after reading across organizations', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->for($organization)->for($user)->create();

    $tenant = resolve(TenantContext::class);
    $tenant->set($organization);

    resolve(MembershipRepository::class)->organizationsFor($user);

    expect($tenant->id())->toBe($organization->id)
        ->and($tenant->current()?->id)->toBe($organization->id);
});

it('finds the organizations a departing owner would orphan', function (): void {
    $owner = User::factory()->create();
    $personal = Organization::factory()->personal()->create(['owner_id' => $owner->id]);
    $shared = Organization::factory()->create(['owner_id' => $owner->id, 'name' => 'Acme']);
    $alone = Organization::factory()->create(['owner_id' => $owner->id]);

    Membership::factory()->for($personal)->for($owner)->create();
    Membership::factory()->for($shared)->for($owner)->create();
    Membership::factory()->for($shared)->for(User::factory())->create();
    Membership::factory()->for($alone)->for($owner)->create();

    $shared = resolve(MembershipRepository::class)->sharedOrganizationsOwnedBy($owner);

    expect($shared->pluck('name')->all())->toBe(['Acme']);
});
