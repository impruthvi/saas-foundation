<?php

declare(strict_types=1);

use App\Actions\AcceptOrganizationInvitation;
use App\Billing\PlanCatalog;
use App\Enums\InvitationStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\Invitations\SeatLimitReached;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Tests\Support\Contenders;
use Tests\Support\Outcome;

function attemptSeatAcceptance(int $invitationId, int $userId): Outcome
{
    try {
        resolve(AcceptOrganizationInvitation::class)->handle(
            Invitation::query()->findOrFail($invitationId),
            User::query()->findOrFail($userId),
        );

        return Outcome::Succeeded;
    } catch (SeatLimitReached) {
        return Outcome::Refused;
    }
}

it('admits exactly one simultaneous acceptance when one seat remains', function (): void {
    $billing = config('billing');

    foreach ($billing['plans'] as $key => &$plan) {
        foreach ($plan['prices'] as &$price) {
            $price['allowances']['seats'] = $key === 'free' ? 2 : 10;
        }
    }

    unset($plan, $price);

    config(['billing' => $billing]);
    app()->forgetInstance(PlanCatalog::class);
    app()->forgetInstance(PriceCatalog::class);

    [$organization, $owner] = organizationOwnedBySomeone();
    $first = User::factory()->create(['email' => 'first-seat@example.com']);
    $second = User::factory()->create(['email' => 'second-seat@example.com']);
    $firstInvitationId = findInvitation(issueInvitation($organization, $first->email, $owner))->id;
    $secondInvitationId = findInvitation(issueInvitation($organization, $second->email, $owner))->id;

    $outcomes = Contenders::race([
        fn (): Outcome => attemptSeatAcceptance($firstInvitationId, $first->id),
        fn (): Outcome => attemptSeatAcceptance($secondInvitationId, $second->id),
    ]);

    $outcomeNames = array_map(fn (Outcome $outcome): string => $outcome->name, $outcomes);
    sort($outcomeNames);

    $membershipCount = resolve(TenantContext::class)->runFor($organization, fn (): int => Membership::query()
        ->where('status', MembershipStatus::Active)
        ->count());

    expect($outcomeNames)->toBe([Outcome::Refused->name, Outcome::Succeeded->name])
        ->and($membershipCount)->toBe(2)
        ->and(Invitation::query()->where('organization_id', $organization->id)->where('status', InvitationStatus::Accepted)->count())->toBe(1)
        ->and(Invitation::query()->where('organization_id', $organization->id)->where('status', InvitationStatus::Pending)->count())->toBe(1);
});
