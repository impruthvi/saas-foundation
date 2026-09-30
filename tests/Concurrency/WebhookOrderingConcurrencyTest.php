<?php

declare(strict_types=1);

use App\Billing\ApplyStripeEvent;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionEventWatermark;
use App\Tenancy\TenantContext;
use Tests\Support\Contenders;
use Tests\Support\Outcome;

/**
 * Applies a subscription event through the ordering guard, writing only the status, so
 * the race is between the guard and the write rather than Cashier's handler.
 */
function applySubscriptionStatus(string $eventId, string $type, int $createdAt, string $status, int $delayBeforeWriteMicroseconds): Outcome
{
    $payload = [
        'id' => $eventId,
        'type' => $type,
        'created' => $createdAt,
        'livemode' => false,
        'data' => ['object' => ['id' => 'sub_race', 'customer' => 'cus_race']],
    ];

    resolve(ApplyStripeEvent::class)->handle($payload, function () use ($status, $delayBeforeWriteMicroseconds): bool {
        // Real time, not Sleep: the suite fakes Sleep, and the race needs the delay.
        time_nanosleep(0, $delayBeforeWriteMicroseconds * 1_000);

        return Subscription::query()->where('stripe_id', 'sub_race')->update(['stripe_status' => $status]) === 1;
    });

    return Outcome::Succeeded;
}

it('lets a newer event that arrives mid-apply win over the older one it overtook', function (): void {
    $organization = Organization::factory()->create(['stripe_id' => 'cus_race']);

    resolve(TenantContext::class)->runFor($organization, function (): void {
        Subscription::query()->create(['type' => 'default', 'stripe_id' => 'sub_race', 'stripe_status' => 'active', 'quantity' => 1]);
        SubscriptionEventWatermark::query()->create(['stripe_id' => 'sub_race', 'event_created_at' => 900]);
    });

    // The older update claims first and writes slowly; the deletion arrives while it is
    // still writing and must not be overwritten by it.
    $outcomes = Contenders::race([
        fn (): Outcome => applySubscriptionStatus('evt_older', 'customer.subscription.updated', 1_000, 'past_due', 600_000),
        function (): Outcome {
            time_nanosleep(0, 200_000_000);

            return applySubscriptionStatus('evt_newer', 'customer.subscription.deleted', 1_001, 'canceled', 0);
        },
    ]);

    $status = resolve(TenantContext::class)->runFor(
        $organization,
        fn (): string => Subscription::query()->where('stripe_id', 'sub_race')->sole()->stripe_status,
    );

    expect($outcomes)->toBe([Outcome::Succeeded, Outcome::Succeeded])
        ->and($status)->toBe('canceled');
});
