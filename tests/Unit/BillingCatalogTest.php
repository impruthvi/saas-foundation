<?php

declare(strict_types=1);

use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\Price;

/** @return array<string, mixed> */
function configuredBillingCatalog(): array
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

it('builds plans and prices from configuration', function (): void {
    $catalog = PlanCatalog::fromConfig(configuredBillingCatalog());

    expect($catalog->plans())->toHaveCount(2)->toContainOnlyInstancesOf(Plan::class)
        ->and($catalog->priceIds())->toBe(['price_free', 'price_pro'])
        ->and($catalog->findPrice('price_pro'))->toBeInstanceOf(Price::class)->key->toBe('pro_monthly')->planKey->toBe('pro')->interval->toBe('month')->currency->toBe('usd')->amount->toBe(2000)->allowances->toBe(['projects' => 10, 'exports' => true])
        ->and($catalog->findPrice('price_pro')?->environmentVariable())->toBe('STRIPE_PRICE_PRO_MONTHLY')
        ->and($catalog->findPrice('price_unknown'))->toBeNull()
        ->and($catalog->findPlan('pro')?->name)->toBe('Pro');
});

it('is registered once from application configuration', function (): void {
    $catalog = resolve(PlanCatalog::class);

    expect($catalog)->toBe(resolve(PlanCatalog::class))
        ->and($catalog->findPlan('free')?->name)->toBe('Free')
        ->and($catalog->findPlan('pro')?->name)->toBe('Pro');
});

it('rejects a price assigned to more than one plan', function (): void {
    $configuration = configuredBillingCatalog();
    $configuration['plans']['pro']['prices']['price_free'] = $configuration['plans']['pro']['prices']['price_pro'];

    PlanCatalog::fromConfig($configuration);
})->throws(InvalidArgumentException::class, 'belongs to more than one plan');

it('rejects repeated application or Stripe lookup keys', function (string $field, string $message): void {
    $configuration = configuredBillingCatalog();
    $configuration['plans']['pro']['prices']['price_pro_yearly'] = [
        ...$configuration['plans']['pro']['prices']['price_pro'],
        'key' => 'pro_yearly',
        'lookup_key' => 'pro_yearly',
        'interval' => 'year',
    ];
    $configuration['plans']['pro']['prices']['price_pro_yearly'][$field] = 'pro_monthly';
    $configuration['plans']['pro']['prices']['price_pro']['lookup_key'] = 'pro_monthly';

    expect(fn (): PlanCatalog => PlanCatalog::fromConfig($configuration))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'application key' => ['key', 'Billing price key'],
    'Stripe lookup key' => ['lookup_key', 'Stripe lookup key'],
]);

it('rejects a feature whose allowance changes type between prices', function (): void {
    $configuration = configuredBillingCatalog();
    $configuration['plans']['pro']['prices']['price_pro']['allowances']['exports'] = 10;

    PlanCatalog::fromConfig($configuration);
})->throws(InvalidArgumentException::class, 'changes type between prices');

it('rejects malformed catalog configuration', function (mixed $configuration): void {
    PlanCatalog::fromConfig($configuration);
})->throws(InvalidArgumentException::class)->with([
    'configuration is not an array' => ['invalid'],
    'plans are missing' => [[]],
    'plan name is missing' => [[
        'plans' => [
            'free' => ['prices' => []],
        ],
    ]],
    'price amount is not an integer' => [[
        'plans' => [
            'pro' => [
                'name' => 'Pro',
                'prices' => [
                    'price_pro' => [
                        'interval' => 'month',
                        'currency' => 'usd',
                        'amount' => '20.00',
                        'allowances' => ['projects' => 10],
                    ],
                ],
            ],
        ],
    ]],
    'allowance is negative' => [[
        'plans' => [
            'pro' => [
                'name' => 'Pro',
                'prices' => [
                    'price_pro' => [
                        'interval' => 'month',
                        'currency' => 'usd',
                        'amount' => 2000,
                        'allowances' => ['projects' => -1],
                    ],
                ],
            ],
        ],
    ]],
    'unexpected price key' => [[
        'plans' => [
            'pro' => [
                'name' => 'Pro',
                'prices' => [
                    'price_pro' => [
                        'interval' => 'month',
                        'currency' => 'usd',
                        'amount' => 2000,
                        'allowances' => ['projects' => 10],
                        'ammount' => 2000,
                    ],
                ],
            ],
        ],
    ]],
]);
