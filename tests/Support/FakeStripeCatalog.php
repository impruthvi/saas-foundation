<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;
use Stripe\Collection;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;

/**
 * Stripe's products and prices, kept in memory, for provisioning a catalog.
 */
final class FakeStripeCatalog extends StripeClient
{
    /** @var list<array<string, mixed>> */
    public array $createdProducts = [];

    /** @var list<array<string, mixed>> */
    public array $createdPrices = [];

    /** @var array<string, string> lookup key => price id */
    public array $pricesByLookupKey = [];

    public ?ApiErrorException $failure = null;

    public function __construct()
    {
        parent::__construct('sk_test_inert');
    }

    public function withPrice(string $lookupKey, string $priceId): self
    {
        $this->pricesByLookupKey[$lookupKey] = $priceId;

        return $this;
    }

    public function getService(mixed $name): mixed
    {
        return match ($name) {
            'prices' => new FakeStripePriceService($this),
            'products' => new FakeStripeProductService($this),
            default => throw new LogicException("Unexpected Stripe service [{$name}]."),
        };
    }

    public function failIfAsked(): void
    {
        if ($this->failure instanceof ApiErrorException) {
            throw $this->failure;
        }
    }
}

final readonly class FakeStripePriceService
{
    public function __construct(private FakeStripeCatalog $stripe) {}

    /** @param array{lookup_keys?: list<string>} $parameters */
    public function all(array $parameters = []): Collection
    {
        $this->stripe->failIfAsked();

        $data = [];

        foreach ($parameters['lookup_keys'] ?? [] as $lookupKey) {
            if (isset($this->stripe->pricesByLookupKey[$lookupKey])) {
                $data[] = ['id' => $this->stripe->pricesByLookupKey[$lookupKey], 'object' => 'price', 'lookup_key' => $lookupKey];
            }
        }

        return Collection::constructFrom(['object' => 'list', 'data' => $data, 'has_more' => false]);
    }

    /** @param array<string, mixed> $parameters */
    public function create(array $parameters): Price
    {
        $this->stripe->failIfAsked();
        $this->stripe->createdPrices[] = $parameters;

        return Price::constructFrom(['id' => 'price_created_'.count($this->stripe->createdPrices), 'object' => 'price']);
    }
}

final readonly class FakeStripeProductService
{
    public function __construct(private FakeStripeCatalog $stripe) {}

    /** @param array<string, mixed> $parameters */
    public function create(array $parameters): Product
    {
        $this->stripe->failIfAsked();
        $this->stripe->createdProducts[] = $parameters;

        return Product::constructFrom(['id' => 'prod_created_'.count($this->stripe->createdProducts), 'object' => 'product']);
    }
}
