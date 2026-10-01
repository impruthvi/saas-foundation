<?php

declare(strict_types=1);

use App\Actions\AcceptOrganizationInvitation;
use App\Actions\RemoveOrganizationMember;
use App\Billing\PlanCatalog;
use App\Enums\InvitationStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\Invitations\SeatLimitReached;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;

function configureSeatAllowance(?int $free, ?int $pro = null): void
{
    $billing = config('billing');

    foreach ($billing['plans'] as $key => &$plan) {
        foreach ($plan['prices'] as &$price) {
            $price['allowances']['seats'] = $key === 'free' ? $free : $pro;
        }
    }

    unset($plan, $price);

    config(['billing' => $billing]);
    app()->forgetInstance(PlanCatalog::class);
    app()->forgetInstance(PriceCatalog::class);
}

it('leaves pending invitations uncounted and refuses acceptance when active memberships fill the limit', function (): void {
    configureSeatAllowance(2, 10);
    [$organization, $owner] = organizationOwnedBySomeone();
    $first = User::factory()->create(['email' => 'first@example.com']);
    $second = User::factory()->create(['email' => 'second@example.com']);
    $firstToken = issueInvitation($organization, $first->email, $owner);
    $secondToken = issueInvitation($organization, $second->email, $owner);

    throughTheAuditedDoor(fn () => $this->actingAs($first)
        ->get(route('invitations.show', ['token' => $firstToken]))
        ->assertInertia(fn ($page) => $page->where('refusal', null)));

    resolve(AcceptOrganizationInvitation::class)->handle(findInvitation($firstToken), $first);

    throughTheAuditedDoor(fn () => $this->actingAs($second)
        ->get(route('invitations.show', ['token' => $secondToken]))
        ->assertInertia(fn ($page) => $page->where('refusal', fn (?string $message): bool => str_contains((string) $message, 'seat limit'))));

    throughTheAuditedDoor(fn () => $this->actingAs($second)
        ->post(route('invitations.accept', ['token' => $secondToken]))
        ->assertSessionHasErrors('invitation'));

    $state = resolve(TenantContext::class)->runFor($organization, fn (): array => [
        Membership::query()->where('status', MembershipStatus::Active)->count(),
        findInvitation($secondToken)?->status,
    ]);

    expect($state)->toBe([2, InvitationStatus::Pending]);

    $firstMembership = resolve(TenantContext::class)->runFor($organization, fn (): Membership => Membership::query()->where('user_id', $first->id)->sole());
    resolve(RemoveOrganizationMember::class)->handle($firstMembership);
    resolve(AcceptOrganizationInvitation::class)->handle(findInvitation($secondToken), $second);

    expect(resolve(TenantContext::class)->runFor($organization, fn (): int => Membership::query()->where('status', MembershipStatus::Active)->count()))->toBe(2);
});

it('accepts beyond the initial count when the configured seat allowance is unlimited', function (): void {
    configureSeatAllowance(null);
    [$organization, $owner] = organizationOwnedBySomeone();
    $invitee = User::factory()->create(['email' => 'unlimited@example.com']);
    $token = issueInvitation($organization, $invitee->email, $owner);

    expect(fn (): Membership => resolve(AcceptOrganizationInvitation::class)->handle(findInvitation($token), $invitee))
        ->not->toThrow(SeatLimitReached::class);
});
