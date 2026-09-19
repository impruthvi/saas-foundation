<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Enums\MembershipRole;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| The members screen does what its name says
|--------------------------------------------------------------------------
|
| The two flows a feature test cannot reach: whether the controls are on the
| page at all, and whether the confirmation actually removes somebody.
|
*/

it('shows removal to an administrator and not to a plain member', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $member);

    $asOwner = visit('/organizations/members')
        ->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);

    $asOwner->assertSee($member->name)
        ->assertSee('Remove')
        ->assertNoJavaScriptErrors();

    visit('/organizations/members')
        ->actingAs($member)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->assertSee($member->name)
        ->assertDontSee('Remove')
        ->assertNoJavaScriptErrors();
});

it('removes a member through the confirmation', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $member, MembershipRole::Admin);

    $page = visit('/organizations/members')
        ->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);

    $page->assertSee($member->name)
        ->press('Remove')
        ->assertSee('Remove this member?')
        ->press('Remove')
        ->assertDontSee($member->email)
        ->assertNoJavaScriptErrors();
});
