<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Enums\MembershipRank;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\User;

it('shows removal to an administrator and not to a plain member', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $member);

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);

    $asOwner = visit('/organizations/members');

    $asOwner->assertSee($member->name)
        ->assertSee('Remove')
        ->assertNoJavaScriptErrors();

    $this->actingAs($member)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);

    visit('/organizations/members')
        ->assertSee($member->name)
        ->assertDontSee('Remove')
        ->assertNoJavaScriptErrors();
});

it('removes a member through the confirmation', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $member = User::factory()->create();
    resolve(AddOrganizationMember::class)->handle($organization, $member, MembershipRank::Admin);

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);

    $page = visit('/organizations/members');

    $page->assertSee($member->name)
        ->press('Remove')
        ->assertSee('Remove this member?')
        ->click('[role="dialog"] button[type="submit"]')
        ->assertDontSee($member->email)
        ->assertNoJavaScriptErrors();
});
