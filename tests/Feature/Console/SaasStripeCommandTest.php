<?php

declare(strict_types=1);

use App\Billing\PlanCatalog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Stripe\Exception\ApiConnectionException;
use Stripe\StripeClient;
use Tests\Support\FakeStripeCatalog;

/**
 * @return array{stripe: FakeStripeCatalog, env: string, keys: list<mixed>}
 */
function stripeSetup(string $envContents = "APP_NAME=Laravel\nSTRIPE_SECRET=\n"): array
{
    $stripe = new FakeStripeCatalog();
    $keys = [];
    app()->bind(StripeClient::class, function ($app, array $parameters) use ($stripe, &$keys): StripeClient {
        $keys[] = $parameters['config']['api_key'] ?? null;

        return $stripe;
    });

    $directory = sys_get_temp_dir().'/saas-stripe-'.bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory.'/.env', $envContents);
    app()->useEnvironmentPath($directory);

    return ['stripe' => $stripe, 'env' => $directory.'/.env', 'keys' => &$keys];
}

it('refuses anything but a test secret key and changes nothing', function (string $key): void {
    $setup = stripeSetup();
    Process::fake();

    $this->artisan('saas:stripe', ['key' => $key])
        ->expectsOutputToContain('Use a Stripe test secret key')
        ->assertFailed();

    expect(file_get_contents($setup['env']))->toBe("APP_NAME=Laravel\nSTRIPE_SECRET=\n")
        ->and($setup['keys'])->toBeEmpty();
    Process::assertNothingRan();
})->with([
    'live secret key' => 'sk_live_abc',
    'publishable key' => 'pk_test_abc',
    'garbage' => 'not-a-key',
]);

it('creates the Pro price and writes the keys and webhook secret', function (): void {
    $setup = stripeSetup();
    Process::fake(['*stripe*listen*' => 'Ready! Your webhook signing secret is whsec_demo123 (^C to quit)']);

    $exitCode = Artisan::call('saas:stripe', ['key' => 'sk_test_demo']);

    $env = file_get_contents($setup['env']);

    expect($exitCode)->toBe(0)
        ->and($setup['keys'])->toBe(['sk_test_demo'])
        ->and($setup['stripe']->createdProducts)->toBe([['name' => 'Pro']])
        ->and($setup['stripe']->createdPrices)->toBe([[
            'product' => 'prod_created_1',
            'unit_amount' => 2000,
            'currency' => 'usd',
            'recurring' => ['interval' => 'month'],
            'lookup_key' => 'pro_month',
        ]])
        ->and($env)->toContain('STRIPE_SECRET="sk_test_demo"', 'STRIPE_PRICE_PRO_MONTHLY="price_created_1"', 'STRIPE_WEBHOOK_SECRET="whsec_demo123"')
        ->and($env)->not->toContain("STRIPE_SECRET=\n");
    Process::assertRan(fn ($process): bool => $process->command === ['stripe', 'listen', '--api-key', 'sk_test_demo', '--print-secret']);
});

it('reuses a price it finds by lookup key instead of creating another', function (): void {
    $setup = stripeSetup();
    $setup['stripe']->withPrice('pro_month', 'price_existing');
    Process::fake(['*stripe*listen*' => 'whsec_demo123']);

    Artisan::call('saas:stripe', ['key' => 'sk_test_demo']);

    expect($setup['stripe']->createdProducts)->toBeEmpty()
        ->and($setup['stripe']->createdPrices)->toBeEmpty()
        ->and(file_get_contents($setup['env']))->toContain('STRIPE_PRICE_PRO_MONTHLY="price_existing"');
});

it('replaces a price that no longer matches the catalog instead of reusing it', function (int $amount, string $currency, string $interval): void {
    $setup = stripeSetup();
    $setup['stripe']->withPrice('pro_month', 'price_stale', $amount, $currency, $interval);
    Process::fake(['*stripe*listen*' => 'whsec_demo123']);

    Artisan::call('saas:stripe', ['key' => 'sk_test_demo']);

    expect($setup['stripe']->createdProducts)->toBeEmpty()
        ->and($setup['stripe']->createdPrices)->toBe([[
            'product' => 'prod_existing',
            'unit_amount' => 2000,
            'currency' => 'usd',
            'recurring' => ['interval' => 'month'],
            'lookup_key' => 'pro_month',
            'transfer_lookup_key' => true,
        ]])
        ->and(file_get_contents($setup['env']))->toContain('STRIPE_PRICE_PRO_MONTHLY="price_created_1"');
})->with([
    'a different amount' => [1500, 'usd', 'month'],
    'a different currency' => [2000, 'eur', 'month'],
    'a different interval' => [2000, 'usd', 'year'],
]);

it('reports setup as incomplete when the Stripe CLI gives no webhook secret', function (): void {
    $setup = stripeSetup();
    Process::fake(['*stripe*listen*' => Process::result(errorOutput: 'stripe: command not found', exitCode: 127)]);

    $exitCode = Artisan::call('saas:stripe', ['key' => 'sk_test_demo']);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Setup is incomplete', 'https://docs.stripe.com/stripe-cli')
        ->and(file_get_contents($setup['env']))
        ->toContain('STRIPE_PRICE_PRO_MONTHLY="price_created_1"')
        ->not->toContain('STRIPE_WEBHOOK_SECRET');
});

it('leaves .env untouched when Stripe refuses the request', function (): void {
    $setup = stripeSetup();
    $setup['stripe']->failure = ApiConnectionException::factory('Network unavailable.');
    Process::fake();

    $this->artisan('saas:stripe', ['key' => 'sk_test_demo'])
        ->expectsOutputToContain('Stripe refused price [pro_monthly]')
        ->assertFailed();

    expect(file_get_contents($setup['env']))->toBe("APP_NAME=Laravel\nSTRIPE_SECRET=\n");
    Process::assertNothingRan();
});

it('provisions every paid catalog price and reuses matching prices on another run', function (): void {
    config([
        'billing.plans.pro.prices.price_pro_yearly' => [
            'key' => 'pro_yearly',
            'interval' => 'year',
            'currency' => 'usd',
            'amount' => 20000,
            'allowances' => ['projects' => 10],
        ],
        'billing.plans.growth' => [
            'name' => 'Growth',
            'prices' => [
                'price_growth_monthly' => [
                    'key' => 'growth_monthly',
                    'interval' => 'month',
                    'currency' => 'usd',
                    'amount' => 5000,
                    'allowances' => ['projects' => 30],
                ],
            ],
        ],
    ]);
    app()->forgetInstance(PlanCatalog::class);
    $setup = stripeSetup();
    $setup['stripe']->withPrice('growth_monthly', 'price_growth_existing', 5000);
    Process::fake(['*stripe*listen*' => 'whsec_demo123']);

    expect(Artisan::call('saas:stripe', ['key' => 'sk_test_demo']))->toBe(0);

    $env = file_get_contents($setup['env']);

    expect($setup['stripe']->createdPrices)->toHaveCount(2)
        ->and($env)->toContain(
            'STRIPE_PRICE_PRO_MONTHLY="price_created_1"',
            'STRIPE_PRICE_PRO_YEARLY="price_created_2"',
            'STRIPE_PRICE_GROWTH_MONTHLY="price_growth_existing"',
        );

    $setup['stripe']->withPrice('pro_month', 'price_created_1');
    $setup['stripe']->withPrice('pro_yearly', 'price_created_2', 20000, 'usd', 'year');

    expect(Artisan::call('saas:stripe', ['key' => 'sk_test_demo']))->toBe(0)
        ->and($setup['stripe']->createdPrices)->toHaveCount(2);

    $setup['stripe']->withPrice('pro_yearly', 'price_stale', 19000, 'usd', 'year');

    expect(Artisan::call('saas:stripe', ['key' => 'sk_test_demo']))->toBe(0)
        ->and($setup['stripe']->createdPrices)->toHaveCount(3)
        ->and($setup['stripe']->createdPrices[2])->toMatchArray([
            'product' => 'prod_existing',
            'lookup_key' => 'pro_yearly',
            'transfer_lookup_key' => true,
        ])
        ->and(file_get_contents($setup['env']))->toContain(
            'STRIPE_PRICE_PRO_MONTHLY="price_created_1"',
            'STRIPE_PRICE_PRO_YEARLY="price_created_3"',
            'STRIPE_PRICE_GROWTH_MONTHLY="price_growth_existing"',
        );
});

it('reports the failing price without writing a partially provisioned catalog', function (): void {
    config(['billing.plans.pro.prices.price_pro_yearly' => [
        'key' => 'pro_yearly',
        'interval' => 'year',
        'currency' => 'usd',
        'amount' => 20000,
        'allowances' => ['projects' => 10],
    ]]);
    app()->forgetInstance(PlanCatalog::class);
    $setup = stripeSetup();
    $setup['stripe']->failOnLookupKey = 'pro_yearly';
    Process::fake();

    $this->artisan('saas:stripe', ['key' => 'sk_test_demo'])
        ->expectsOutputToContain('Stripe refused price [pro_yearly]')
        ->assertFailed();

    expect($setup['stripe']->createdPrices)->toHaveCount(1)
        ->and(file_get_contents($setup['env']))->toBe("APP_NAME=Laravel\nSTRIPE_SECRET=\n");
    Process::assertNothingRan();
});
