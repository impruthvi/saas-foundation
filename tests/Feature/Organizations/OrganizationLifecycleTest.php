<?php

declare(strict_types=1);

use App\Actions\ChangeOrganizationStatus;
use App\Enums\AuditAction;
use App\Enums\OrganizationStatus;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\AuditEvent;
use App\Models\Operator;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Testing\AssertableInertia;

function lifecycleOperator(): User
{
    $operator = User::factory()->create();
    Operator::factory()->create(['user_id' => $operator->id]);
    test()->actingAs($operator);

    return $operator;
}

it('suspends, archives and restores an organization with attributed audit events', function (): void {
    $operator = lifecycleOperator();
    [$organization, $owner] = organizationOwnedBySomeone('Customer');
    $change = resolve(ChangeOrganizationStatus::class);

    $change->handle($organization, $operator, OrganizationStatus::Suspended, 'Abuse report');

    expect($organization->fresh()?->status)->toBe(OrganizationStatus::Suspended);

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('organization', null));

    $this->actingAs($operator);
    $change->handle($organization, $operator, OrganizationStatus::Archived, 'Long-term closure');
    $change->handle($organization, $operator, OrganizationStatus::Active, 'Review completed');

    expect($organization->fresh()?->status)->toBe(OrganizationStatus::Active);

    $events = resolve(TenantContext::class)->runFor($organization, fn () => AuditEvent::query()
        ->where('action', AuditAction::OrganizationStatusChanged)
        ->orderBy('id')
        ->get());

    expect($events)->toHaveCount(3)
        ->and($events->pluck('actor_id')->all())->toBe([$operator->id, $operator->id, $operator->id])
        ->and($events->pluck('context.to')->all())->toBe(['suspended', 'archived', 'active'])
        ->and($events->pluck('context.reason')->all())->toBe(['Abuse report', 'Long-term closure', 'Review completed']);
});

it('refuses suspension and archiving while a subscription is open', function (string $stripeStatus, ?int $endsAtOffset): void {
    $operator = lifecycleOperator();
    [$organization] = organizationOwnedBySomeone('Customer');

    resolve(TenantContext::class)->runFor($organization, fn (): Subscription => Subscription::query()->create([
        'type' => 'default',
        'stripe_id' => 'sub_lifecycle_'.$organization->id,
        'stripe_status' => $stripeStatus,
        'stripe_price' => 'price_pro_monthly',
        'quantity' => 1,
        'ends_at' => $endsAtOffset === null ? null : now()->addDays($endsAtOffset),
    ]));

    foreach ([OrganizationStatus::Suspended, OrganizationStatus::Archived] as $target) {
        expect(fn () => resolve(ChangeOrganizationStatus::class)->handle($organization, $operator, $target, 'Review'))
            ->toThrow(DomainException::class, 'End the organization subscription');
    }

    expect($organization->fresh()?->status)->toBe(OrganizationStatus::Active);
})->with([
    'active' => ['active', null],
    'grace period' => ['active', 7],
    'past due' => ['past_due', null],
    'incomplete' => ['incomplete', null],
]);

it('allows suspension after the subscription has ended', function (): void {
    $operator = lifecycleOperator();
    [$organization] = organizationOwnedBySomeone('Customer');
    resolve(TenantContext::class)->runFor($organization, fn (): Subscription => Subscription::query()->create([
        'type' => 'default',
        'stripe_id' => 'sub_ended_'.$organization->id,
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_pro_monthly',
        'quantity' => 1,
        'ends_at' => now()->subDay(),
    ]));

    resolve(ChangeOrganizationStatus::class)->handle($organization, $operator, OrganizationStatus::Suspended, 'Review');

    expect($organization->fresh()?->status)->toBe(OrganizationStatus::Suspended);
});

it('requires an operator and a reason and refuses invalid transitions', function (): void {
    $operator = lifecycleOperator();
    [$organization] = organizationOwnedBySomeone('Customer');
    $change = resolve(ChangeOrganizationStatus::class);

    expect(fn () => $change->handle($organization, User::factory()->create(), OrganizationStatus::Suspended, 'Review'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $change->handle($organization, $operator, OrganizationStatus::Suspended, ' '))->toThrow(DomainException::class, 'reason is required')
        ->and(fn () => $change->handle($organization, $operator, OrganizationStatus::Active, 'Review'))->toThrow(DomainException::class, 'already active');

    $change->handle($organization, $operator, OrganizationStatus::Archived, 'Review');

    expect(fn () => $change->handle($organization, $operator, OrganizationStatus::Suspended, 'Review'))
        ->toThrow(DomainException::class, 'Restore an archived organization');
});
