<?php

declare(strict_types=1);

namespace App\Actions;

use App\Billing\Plan;
use App\Billing\Price;
use InvalidArgumentException;
use Stripe\Price as StripePrice;
use Stripe\Product;
use Stripe\StripeClient;

/**
 * Finds a catalog price in a Stripe account by lookup key, creating the product and
 * price only when none exists, so running it twice never duplicates them. A price whose
 * amount, currency or interval no longer matches the catalog is replaced, not reused:
 * the screen shows the catalog while Checkout charges the Stripe price.
 */
final readonly class ProvisionStripeCatalog
{
    public function handle(StripeClient $stripe, Plan $plan, Price $price): string
    {
        throw_unless($price->planKey === $plan->key && $price->amount > 0, InvalidArgumentException::class, "Price [{$price->key}] is not a paid price of plan [{$plan->key}].");

        $lookupKey = $price->lookupKey;

        $existing = $stripe->prices->all(['lookup_keys' => [$lookupKey], 'active' => true, 'limit' => 1])->data[0] ?? null;

        if ($existing instanceof StripePrice && $this->matches($existing, $price)) {
            return $existing->id;
        }

        $parameters = [
            'product' => $existing instanceof StripePrice ? $this->productOf($existing) : $this->createProduct($stripe, $plan),
            'unit_amount' => $price->amount,
            'currency' => $price->currency,
            'recurring' => ['interval' => $price->interval],
            'lookup_key' => $lookupKey,
        ];

        if ($existing instanceof StripePrice) {
            $parameters['transfer_lookup_key'] = true;
        }

        return $stripe->prices->create($parameters)->id;
    }

    private function matches(StripePrice $existing, Price $price): bool
    {
        return $existing->unit_amount === $price->amount
            && $existing->currency === $price->currency
            && $existing->recurring?->interval === $price->interval;
    }

    private function productOf(StripePrice $existing): string
    {
        return $existing->product instanceof Product ? $existing->product->id : (string) $existing->product;
    }

    private function createProduct(StripeClient $stripe, Plan $plan): string
    {
        $product = $stripe->products->create(['name' => $plan->name]);

        throw_unless($product instanceof Product, InvalidArgumentException::class, 'Stripe returned an invalid product.');

        return $product->id;
    }
}
