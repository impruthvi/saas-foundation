<?php

declare(strict_types=1);

use App\Billing\PlanCatalog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapping;

/** @return array<string, mixed> */
function entitlementCatalogConfiguration(): array
{
    return [
        'plans' => [
            'free' => [
                'name' => 'Free',
                'prices' => [
                    'price_free' => [
                        'key' => 'free_monthly',
                        'interval' => 'month',
                        'currency' => 'usd',
                        'amount' => 0,
                        'allowances' => ['projects' => 2, 'exports' => false],
                    ],
                ],
            ],
            'pro' => [
                'name' => 'Pro',
                'prices' => [
                    'price_pro' => [
                        'key' => 'pro_monthly',
                        'interval' => 'month',
                        'currency' => 'usd',
                        'amount' => 2000,
                        'allowances' => ['projects' => 10, 'exports' => true],
                    ],
                ],
            ],
        ],
    ];
}

/** @param array<string, mixed> $configuration */
function resolveEntitlementCatalog(array $configuration): PriceCatalog
{
    config(['billing' => $configuration]);
    app()->forgetInstance(PlanCatalog::class);
    app()->forgetInstance(PriceCatalog::class);

    return resolve(PriceCatalog::class);
}

it('registers organization as the entitlement owner without changing other morph types', function (): void {
    expect(Relation::getMorphedModel('organization'))->toBe(Organization::class)
        ->and((new Organization)->getMorphClass())->toBe('organization')
        ->and((new User)->getMorphClass())->toBe(User::class);
});

it('maps the application billing catalog into the package catalog', function (): void {
    $catalog = resolveEntitlementCatalog(entitlementCatalogConfiguration());

    expect($catalog->version)->toMatch('/^v1-[a-f0-9]{12}$/')
        ->and($catalog->prices)->toHaveKeys(['price_free', 'price_pro'])
        ->and($catalog->prices['price_free'])->toBeInstanceOf(PriceMapping::class)
        ->and($catalog->prices['price_free']->planKey)->toBe('free')
        ->and($catalog->prices['price_free']->allowances)->toBe(['exports' => false, 'projects' => 2])
        ->and($catalog->prices['price_pro']->planKey)->toBe('pro')
        ->and($catalog->prices['price_pro']->allowances)->toBe(['exports' => true, 'projects' => 10])
        ->and($catalog->freeAllowances)->toBeEmpty()
        ->and($catalog->providerContext)->toBe('platform')
        ->and($catalog->liveMode)->toBeFalse();
});

it('changes the catalog version for content changes but not configuration order', function (): void {
    $configuration = entitlementCatalogConfiguration();
    $initial = resolveEntitlementCatalog($configuration);

    $changed = $configuration;
    $changed['plans']['pro']['prices']['price_pro']['allowances']['projects'] = 11;
    $changedVersion = resolveEntitlementCatalog($changed)->version;

    $reordered = $configuration;
    $reordered['plans'] = array_reverse($reordered['plans'], true);
    foreach ($reordered['plans'] as &$plan) {
        $plan['prices'] = array_reverse($plan['prices'], true);
        foreach ($plan['prices'] as &$price) {
            $price['allowances'] = array_reverse($price['allowances'], true);
        }
    }

    unset($plan, $price);

    expect($changedVersion)->not->toBe($initial->version)
        ->and(resolveEntitlementCatalog($reordered)->version)->toBe($initial->version);
});

it('configures bounded freshness, lifetime project usage, and scheduled reconciliation', function (): void {
    expect(config('cashier-entitlements.enabled'))->toBeTrue()
        ->and(config('cashier-entitlements.freshness'))->toBe(['max_stale_age' => 3600])
        ->and(config('cashier-entitlements.meters'))->toBe(['projects' => 'lifetime'])
        ->and(config('cashier-entitlements.schedule'))->toBe([
            'owner_type' => 'organization',
            'sweep' => '*/15 * * * *',
            'sweep_limit' => 100,
            'stale_after' => 1800,
            'recover' => '*/5 * * * *',
        ]);
});

it('runs the entitlements package in the mode of the Stripe secret key', function (?string $secret, bool $liveMode): void {
    $original = $_SERVER;
    $_SERVER['STRIPE_SECRET'] = $secret;

    try {
        $configuration = require config_path('cashier-entitlements.php');
    } finally {
        $_SERVER = $original;
    }

    expect($configuration['live_mode'])->toBe($liveMode);
})->with([
    'live secret key' => ['sk_live_abc', true],
    'live restricted key' => ['rk_live_abc', true],
    'test secret key' => ['sk_test_abc', false],
    'no key' => [null, false],
]);
