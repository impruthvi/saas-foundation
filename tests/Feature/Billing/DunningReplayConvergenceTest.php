<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Impruthvi\CashierDunning\Chaos\ChaosRunner;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Runner\ReplayRunner;

/**
 * Pt12: the same events in orders Stripe is entitled to use leave the same
 * subscription facts behind.
 *
 * Only the facts. The replay wraps everything in a transaction it rolls back,
 * so an entitlement refresh requested after commit is discarded and the harness
 * rightly refuses to call a run successful over work it could not observe.
 * Entitlements are therefore off inside the replay, exactly as they are for
 * `composer test:billing`, and whether the resolved entitlement converges is
 * asserted separately once the transaction is gone — see
 * `tests/Feature/Entitlements/PostReplayResolutionTest.php`.
 */
beforeEach(function (): void {
    Config::set('cashier-entitlements.enabled', false);
});

it('leaves identical subscription facts however Stripe orders the delivery', function (): void {
    $report = acrossEveryOwner(fn () => (new ChaosRunner(
        new ReplayRunner(config(), resolve(Kernel::class), resolve(EntitlementResolver::class)),
        DB::connection(),
    ))->run(
        fixture: resolve(FixtureRepository::class)->find('trial-dunning-cancel-reactivate'),
        shuffle: true,
        duplicate: true,
        seed: 7,
        iterations: 3,
    ));

    expect($report->failures())->toBe([])
        ->and($report->passed())->toBeTrue()
        ->and($report->applicablePasses())->toBeGreaterThan(1);
});

it('accepts every event the recording says Stripe sent', function (): void {
    $report = acrossEveryOwner(fn () => (new ReplayRunner(
        config(),
        resolve(Kernel::class),
        resolve(EntitlementResolver::class),
    ))->run(resolve(FixtureRepository::class)->find('trial-dunning-cancel-reactivate')));

    expect($report->failures())->toBe([])
        ->and($report->passed())->toBeTrue()
        ->and($report->assertions)->toBeGreaterThan(0)
        ->and($report->eventsDelivered())->toBeGreaterThan(0);
});
