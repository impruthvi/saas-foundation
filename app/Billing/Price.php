<?php

declare(strict_types=1);

namespace App\Billing;

use InvalidArgumentException;

final readonly class Price
{
    /** @var array<string, bool|int|null> */
    public array $allowances;

    /**
     * Amount in the currency's smallest unit.
     *
     * @param  array<array-key, mixed>  $allowances
     */
    public function __construct(
        public string $id,
        public string $planKey,
        public string $interval,
        public string $currency,
        public int $amount,
        array $allowances,
    ) {
        throw_unless(str_starts_with($id, 'price_'), InvalidArgumentException::class, "Billing price [{$id}] must be a Stripe price id.");

        throw_if(preg_match('/^[a-z][a-z0-9_-]*$/', $planKey) !== 1, InvalidArgumentException::class, "Billing plan key [{$planKey}] is malformed.");

        throw_unless(in_array($interval, ['day', 'week', 'month', 'year'], true), InvalidArgumentException::class, "Billing interval [{$interval}] is unsupported.");

        throw_if(preg_match('/^[a-z]{3}$/', $currency) !== 1, InvalidArgumentException::class, "Billing currency [{$currency}] must be a lowercase ISO code.");

        throw_if($amount < 0, InvalidArgumentException::class, 'Billing price amount cannot be negative.');

        $validatedAllowances = [];

        foreach ($allowances as $feature => $allowance) {
            throw_if(! is_string($feature) || preg_match('/^[a-z][a-z0-9._-]*$/', $feature) !== 1, InvalidArgumentException::class, 'Billing feature keys must be non-empty strings.');

            throw_if(! is_bool($allowance) && ! is_int($allowance) && $allowance !== null, InvalidArgumentException::class, "Allowance [{$feature}] must be boolean, integer, or null.");

            throw_if(is_int($allowance) && $allowance < 0, InvalidArgumentException::class, "Allowance [{$feature}] cannot be negative.");

            $validatedAllowances[$feature] = $allowance;
        }

        throw_if($validatedAllowances === [], InvalidArgumentException::class, "Billing price [{$id}] must declare at least one allowance.");

        $this->allowances = $validatedAllowances;
    }
}
