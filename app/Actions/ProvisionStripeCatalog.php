<?php

declare(strict_types=1);

namespace App\Actions;

use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\Price;
use InvalidArgumentException;
use Stripe\Price as StripePrice;
use Stripe\Product;
use Stripe\StripeClient;

/**
 * Finds the plan's price in a Stripe account by lookup key, creating the product and
 * price only when none exists, so running it twice never duplicates them.
 */
final readonly class ProvisionStripeCatalog
{
    public function __construct(private PlanCatalog $catalog) {}

    public function handle(StripeClient $stripe, string $planKey = 'pro'): string
    {
        $plan = $this->catalog->findPlan($planKey);
        $price = $plan?->prices[0] ?? null;

        throw_unless($plan instanceof Plan && $price instanceof Price && $price->amount > 0, InvalidArgumentException::class, "Plan [{$planKey}] has no paid price to provision.");

        $lookupKey = "{$planKey}_{$price->interval}";

        $existing = $stripe->prices->all(['lookup_keys' => [$lookupKey], 'active' => true, 'limit' => 1]);

        if (($existing->data[0] ?? null) instanceof StripePrice) {
            return $existing->data[0]->id;
        }

        $product = $stripe->products->create(['name' => $plan->name]);

        throw_unless($product instanceof Product, InvalidArgumentException::class, 'Stripe returned an invalid product.');

        return $stripe->prices->create([
            'product' => $product->id,
            'unit_amount' => $price->amount,
            'currency' => $price->currency,
            'recurring' => ['interval' => $price->interval],
            'lookup_key' => $lookupKey,
        ])->id;
    }
}
