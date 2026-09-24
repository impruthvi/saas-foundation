<?php

declare(strict_types=1);

use App\Entitlements\ResolveAllowance;
use App\Models\Organization;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Tests\Support\StripeWebhook;

/**
 * Pt12b: the state a delivery leaves behind resolves to the same entitlement
 * whatever order the delivery arrived in.
 *
 * Asserted here rather than inside the dunning replay, which wraps its work in
 * a transaction it rolls back: a refresh requested after commit is discarded
 * there, so nothing it leaves behind can be resolved. `DunningReplayConvergenceTest`
 * owns the claim that the subscription facts converge; this owns the claim that
 * the answer read off those facts converges too. M4's replay registered no
 * resolver at all, which is the gap this closes.
 */
beforeEach(function (): void {
    config(['cashier.webhook.secret' => StripeWebhook::SECRET]);
});

/**
 * One subscription lifecycle, as a list of deliveries that can be reordered.
 *
 * Each carries its own `created`, because the watermark decides what an
 * out-of-order arrival is allowed to overwrite. Reordering the list is
 * therefore meant to change nothing at all.
 *
 * A Stripe subscription id is globally unique and the watermark table says so,
 * so each caller brings its own rather than sharing one across organizations.
 *
 * @return list<array<string, mixed>>
 */
function subscriptionLifecycle(string $customerId, string $subscriptionId): array
{
    $event = fn (string $id, string $type, int $created, string $status): array => StripeWebhook::subscriptionPayload(
        eventId: $id,
        type: $type,
        customerId: $customerId,
        created: $created,
        status: $status,
        subscriptionId: $subscriptionId,
        itemId: 'si_'.$subscriptionId,
    );

    return [
        $event('evt_created_'.$subscriptionId, 'customer.subscription.created', 1_000, 'active'),
        $event('evt_past_due_'.$subscriptionId, 'customer.subscription.updated', 2_000, 'past_due'),
        $event('evt_recovered_'.$subscriptionId, 'customer.subscription.updated', 3_000, 'active'),
    ];
}

/**
 * Deliver one ordering into a fresh organization and report what it resolved to.
 *
 * The decision is landed first so the resolver has a paid answer to return.
 * A delivery that perturbed the facts would show up as a different allowance
 * or a different status, not as a silently identical floor.
 *
 * @param  list<int>  $order
 * @return array{allowance: int|null, status: string|null, subscriptions: int}
 */
function resolveAfterDelivering(array $order, string $name): array
{
    $slug = mb_strtolower($name);

    $organization = Organization::factory()->create([
        'name' => $name,
        'stripe_id' => 'cus_'.$slug,
    ]);

    $owner = organizationEntitlementOwner($organization);

    applyAllowanceDecision(
        $owner,
        BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'),
        Date::now()->toDateTimeImmutable(),
    );

    $lifecycle = subscriptionLifecycle('cus_'.$slug, 'sub_'.$slug);

    foreach ($order as $index) {
        StripeWebhook::post($lifecycle[$index])->assertOk();
    }

    return resolve(TenantContext::class)->runFor($organization, fn (): array => [
        'allowance' => resolve(ResolveAllowance::class)->handle(
            $owner,
            'projects',
            Date::now()->toDateTimeImmutable(),
        ),
        'status' => Subscription::query()->where('stripe_id', 'sub_'.$slug)->first()?->stripe_status,
        'subscriptions' => Subscription::query()->count(),
    ]);
}

it('resolves the same entitlement however the deliveries were ordered', function (): void {
    $inOrder = resolveAfterDelivering([0, 1, 2], 'InOrder');
    $reversed = resolveAfterDelivering([2, 1, 0], 'Reversed');
    $duplicated = resolveAfterDelivering([1, 0, 2, 1, 2, 0], 'Duplicated');

    expect($reversed)->toBe($inOrder)
        ->and($duplicated)->toBe($inOrder)
        ->and($inOrder['allowance'])->toBe(10)
        ->and($inOrder['status'])->toBe('active')
        ->and($inOrder['subscriptions'])->toBe(1);
});

it('falls to the floor identically when no decision has landed', function (): void {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_undecided']);
    $owner = organizationEntitlementOwner($organization);

    foreach (subscriptionLifecycle('cus_undecided', 'sub_undecided') as $payload) {
        StripeWebhook::post($payload)->assertOk();
    }

    expect(resolve(ResolveAllowance::class)->handle($owner, 'projects', Date::now()->toDateTimeImmutable()))
        ->toBe(2);
});
