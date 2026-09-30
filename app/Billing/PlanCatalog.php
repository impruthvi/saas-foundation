<?php

declare(strict_types=1);

namespace App\Billing;

use InvalidArgumentException;

final readonly class PlanCatalog
{
    /**
     * @param  array<string, Plan>  $plans
     * @param  array<string, Price>  $prices
     */
    private function __construct(
        private array $plans,
        private array $prices,
    ) {}

    public static function fromConfig(mixed $configuration): self
    {
        $configuration = self::arrayAt($configuration, 'billing');
        self::assertOnlyKeys($configuration, ['plans'], 'billing');

        $configuredPlans = self::arrayAt($configuration['plans'] ?? null, 'billing.plans');

        throw_if($configuredPlans === [], InvalidArgumentException::class, 'Billing must declare at least one plan.');

        $plans = [];
        $prices = [];

        foreach ($configuredPlans as $planKey => $configuredPlan) {
            throw_unless(is_string($planKey), InvalidArgumentException::class, 'Billing plan keys must be strings.');

            $configuredPlan = self::arrayAt($configuredPlan, "billing.plans.{$planKey}");
            self::assertOnlyKeys($configuredPlan, ['name', 'prices'], "billing.plans.{$planKey}");

            $name = self::stringAt($configuredPlan['name'] ?? null, "billing.plans.{$planKey}.name");
            $configuredPrices = self::arrayAt(
                $configuredPlan['prices'] ?? null,
                "billing.plans.{$planKey}.prices",
            );
            $planPrices = [];

            foreach ($configuredPrices as $priceId => $configuredPrice) {
                throw_unless(is_string($priceId), InvalidArgumentException::class, "Billing prices for [{$planKey}] must use string ids.");

                throw_if(array_key_exists($priceId, $prices), InvalidArgumentException::class, "Billing price [{$priceId}] belongs to more than one plan.");

                $configuredPrice = self::arrayAt(
                    $configuredPrice,
                    "billing.plans.{$planKey}.prices.{$priceId}",
                );
                self::assertOnlyKeys(
                    $configuredPrice,
                    ['interval', 'currency', 'amount', 'allowances'],
                    "billing.plans.{$planKey}.prices.{$priceId}",
                );

                $amount = $configuredPrice['amount'] ?? null;

                throw_unless(is_int($amount), InvalidArgumentException::class, "Billing price [{$priceId}] amount must be an integer.");

                $price = new Price(
                    id: $priceId,
                    planKey: $planKey,
                    interval: self::stringAt(
                        $configuredPrice['interval'] ?? null,
                        "billing.plans.{$planKey}.prices.{$priceId}.interval",
                    ),
                    currency: self::stringAt(
                        $configuredPrice['currency'] ?? null,
                        "billing.plans.{$planKey}.prices.{$priceId}.currency",
                    ),
                    amount: $amount,
                    allowances: self::arrayAt(
                        $configuredPrice['allowances'] ?? null,
                        "billing.plans.{$planKey}.prices.{$priceId}.allowances",
                    ),
                );

                $prices[$priceId] = $price;
                $planPrices[] = $price;
            }

            $plans[$planKey] = new Plan($planKey, $name, $planPrices);
        }

        $catalog = new self($plans, $prices);
        $catalog->assertFeatureTypesAgree();

        return $catalog;
    }

    /** @return list<Plan> */
    public function plans(): array
    {
        return array_values($this->plans);
    }

    public function findPlan(string $key): ?Plan
    {
        return $this->plans[$key] ?? null;
    }

    /** @return list<string> */
    public function priceIds(): array
    {
        return array_keys($this->prices);
    }

    public function findPrice(string $id): ?Price
    {
        return $this->prices[$id] ?? null;
    }

    /** @return array<array-key, mixed> */
    private static function arrayAt(mixed $value, string $path): array
    {
        throw_unless(is_array($value), InvalidArgumentException::class, "Billing configuration [{$path}] must be an array.");

        return $value;
    }

    private static function stringAt(mixed $value, string $path): string
    {
        throw_if(! is_string($value) || mb_trim($value) === '', InvalidArgumentException::class, "Billing configuration [{$path}] must be a non-empty string.");

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $configuration
     * @param  list<string>  $allowed
     */
    private static function assertOnlyKeys(array $configuration, array $allowed, string $path): void
    {
        $unexpected = array_diff(array_keys($configuration), $allowed);

        if ($unexpected !== []) {
            throw new InvalidArgumentException(
                "Billing configuration [{$path}] contains unexpected keys: ".implode(', ', $unexpected).'.',
            );
        }
    }

    /**
     * A feature is boolean or numeric everywhere; the entitlement package resolves it the
     * same way on every plan.
     */
    private function assertFeatureTypesAgree(): void
    {
        $types = [];

        foreach ($this->prices as $price) {
            foreach ($price->allowances as $feature => $allowance) {
                throw_if(array_key_exists($feature, $types)
                    && $types[$feature] !== $this->allowanceType($allowance), InvalidArgumentException::class, "Billing feature [{$feature}] changes type between prices.");

                $types[$feature] = $this->allowanceType($allowance);
            }
        }
    }

    private function allowanceType(bool|int|null $allowance): string
    {
        return is_bool($allowance) ? 'boolean' : 'numeric';
    }
}
