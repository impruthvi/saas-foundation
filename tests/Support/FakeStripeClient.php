<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Subscription;
use Stripe\SubscriptionItem;

final class FakeStripeClient extends StripeClient
{
    /** @var list<array{parameters: array<string, mixed>, options: array<string, mixed>}> */
    public array $customerRequests = [];

    /** @var list<array{parameters: array<string, mixed>, options: array<string, mixed>}> */
    public array $checkoutRequests = [];

    /** @var list<array{id: string, parameters: array<string, mixed>, options: array<string, mixed>}> */
    public array $subscriptionRequests = [];

    /** @var list<array{id: string, parameters: array<string, mixed>}> */
    public array $subscriptionItemRequests = [];

    public ?ApiErrorException $customerFailure = null;

    public ?ApiErrorException $checkoutFailure = null;

    public ?ApiErrorException $subscriptionFailure = null;

    public int $subscriptionPeriodEnd;

    /** @var array<string, Customer> */
    private array $customers = [];

    /** @var array<string, Session> */
    private array $sessions = [];

    private readonly FakeStripeCustomerService $customerService;

    private readonly FakeStripeCheckoutService $checkoutService;

    private readonly FakeStripeSubscriptionService $subscriptionService;

    private readonly FakeStripeSubscriptionItemService $subscriptionItemService;

    public function __construct()
    {
        parent::__construct('sk_test_inert');

        $this->customerService = new FakeStripeCustomerService($this);
        $this->checkoutService = new FakeStripeCheckoutService($this);
        $this->subscriptionService = new FakeStripeSubscriptionService($this);
        $this->subscriptionItemService = new FakeStripeSubscriptionItemService($this);
        $this->subscriptionPeriodEnd = time() + 2_592_000;
    }

    public function getService(mixed $name): mixed
    {
        return match ($name) {
            'customers' => $this->customerService,
            'checkout' => $this->checkoutService,
            'subscriptions' => $this->subscriptionService,
            'subscriptionItems' => $this->subscriptionItemService,
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

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $options
     */
    public function updateSubscription(string $id, array $parameters, array $options): Subscription
    {
        $this->subscriptionRequests[] = ['id' => $id, 'parameters' => $parameters, 'options' => $options];

        if ($this->subscriptionFailure instanceof ApiErrorException) {
            throw $this->subscriptionFailure;
        }

        return Subscription::constructFrom([
            'id' => $id,
            'object' => 'subscription',
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $parameters */
    public function retrieveSubscriptionItem(string $id, array $parameters): SubscriptionItem
    {
        $this->subscriptionItemRequests[] = ['id' => $id, 'parameters' => $parameters];

        return SubscriptionItem::constructFrom([
            'id' => $id,
            'object' => 'subscription_item',
            'current_period_end' => $this->subscriptionPeriodEnd,
        ]);
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

final readonly class FakeStripeSubscriptionService
{
    public function __construct(private FakeStripeClient $stripe) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>|null  $options
     */
    public function update(string $id, array $parameters, ?array $options = null): Subscription
    {
        return $this->stripe->updateSubscription($id, $parameters, $options ?? []);
    }
}

final readonly class FakeStripeSubscriptionItemService
{
    public function __construct(private FakeStripeClient $stripe) {}

    /** @param array<string, mixed> $parameters */
    public function retrieve(string $id, array $parameters): SubscriptionItem
    {
        return $this->stripe->retrieveSubscriptionItem($id, $parameters);
    }
}
