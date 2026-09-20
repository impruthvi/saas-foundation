<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final class FakeStripeClient extends StripeClient
{
    /** @var list<array{parameters: array<string, mixed>, options: array<string, mixed>}> */
    public array $customerRequests = [];

    /** @var list<array{parameters: array<string, mixed>, options: array<string, mixed>}> */
    public array $checkoutRequests = [];

    public ?ApiErrorException $customerFailure = null;

    public ?ApiErrorException $checkoutFailure = null;

    /** @var array<string, Customer> */
    private array $customers = [];

    /** @var array<string, Session> */
    private array $sessions = [];

    private readonly FakeStripeCustomerService $customerService;

    private readonly FakeStripeCheckoutService $checkoutService;

    public function __construct()
    {
        parent::__construct('sk_test_inert');

        $this->customerService = new FakeStripeCustomerService($this);
        $this->checkoutService = new FakeStripeCheckoutService($this);
    }

    public function getService(mixed $name): mixed
    {
        return match ($name) {
            'customers' => $this->customerService,
            'checkout' => $this->checkoutService,
            default => throw new LogicException("Unexpected Stripe service [{$name}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $options
     */
    public function createCustomer(array $parameters, array $options): Customer
    {
        $this->customerRequests[] = ['parameters' => $parameters, 'options' => $options];

        if ($this->customerFailure instanceof ApiErrorException) {
            throw $this->customerFailure;
        }

        $key = $this->idempotencyKey($options);

        return $this->customers[$key] ??= Customer::constructFrom([
            'id' => 'cus_test_'.(count($this->customers) + 1),
            'object' => 'customer',
        ]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $options
     */
    public function createCheckout(array $parameters, array $options): Session
    {
        $this->checkoutRequests[] = ['parameters' => $parameters, 'options' => $options];

        if ($this->checkoutFailure instanceof ApiErrorException) {
            throw $this->checkoutFailure;
        }

        $key = $this->idempotencyKey($options);
        $number = count($this->sessions) + 1;

        return $this->sessions[$key] ??= Session::constructFrom([
            'id' => "cs_test_{$number}",
            'object' => 'checkout.session',
            'url' => "https://checkout.stripe.test/session/{$number}",
        ]);
    }

    public function uniqueCustomerCount(): int
    {
        return count($this->customers);
    }

    public function uniqueCheckoutCount(): int
    {
        return count($this->sessions);
    }

    /** @param array<string, mixed> $options */
    private function idempotencyKey(array $options): string
    {
        $key = $options['idempotency_key'] ?? null;

        throw_unless(is_string($key) && $key !== '', LogicException::class, 'A Stripe write omitted its idempotency key.');

        return $key;
    }
}

final readonly class FakeStripeCustomerService
{
    public function __construct(private FakeStripeClient $stripe) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $options
     */
    public function create(array $parameters, array $options): Customer
    {
        return $this->stripe->createCustomer($parameters, $options);
    }
}

final readonly class FakeStripeCheckoutService
{
    public FakeStripeCheckoutSessionService $sessions;

    public function __construct(FakeStripeClient $stripe)
    {
        $this->sessions = new FakeStripeCheckoutSessionService($stripe);
    }
}

final readonly class FakeStripeCheckoutSessionService
{
    public function __construct(private FakeStripeClient $stripe) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $options
     */
    public function create(array $parameters, array $options): Session
    {
        return $this->stripe->createCheckout($parameters, $options);
    }
}
