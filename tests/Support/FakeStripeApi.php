<?php

declare(strict_types=1);

namespace Tests\Support;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Answers Stripe reads without a network, one layer below the client.
 *
 * `FakeStripeClient` cannot serve the entitlement refresh: the package's
 * `StripeSubscriptionSource` deliberately builds its own `StripeClient` from
 * the credentials and base URL of the one it is given, so that it never
 * inherits another caller's account context. Whatever client is injected, the
 * source reads through a fresh one.
 *
 * The seam that survives that is the SDK's own global HTTP client, which every
 * client routes through. Installed for the duration of a test and removed
 * after, so nothing here leaks into a suite that expects the real transport.
 */
final class FakeStripeApi implements ClientInterface
{
    /** @var list<string> */
    public array $requested = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $resources = ['subscriptions' => [], 'subscription_items' => []];

    public static function install(): self
    {
        $api = new self();

        ApiRequestor::setHttpClient($api);

        return $api;
    }

    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    /**
     * Report one active subscription on a price, with the single item that
     * carries it. Both records are shaped the way the source validates them:
     * a missing `cancel_at` or a `livemode` of the wrong kind is rejected
     * there as malformed provider data rather than ignored.
     */
    public function withActiveSubscription(
        string $customerId,
        string $priceId,
        string $subscriptionId = 'sub_fake',
        string $status = 'active',
    ): self {
        $periodEnd = time() + 2_592_000;

        $this->resources['subscriptions'][] = [
            'id' => $subscriptionId,
            'object' => 'subscription',
            'customer' => $customerId,
            'livemode' => false,
            'status' => $status,
            'metadata' => [],
            'cancel_at_period_end' => false,
            'cancel_at' => null,
            'trial_end' => null,
        ];

        $this->resources['subscription_items'][] = [
            'id' => $subscriptionId.'_item',
            'object' => 'subscription_item',
            'subscription' => $subscriptionId,
            'quantity' => 1,
            'current_period_start' => $periodEnd - 2_592_000,
            'current_period_end' => $periodEnd,
            'price' => ['id' => $priceId, 'object' => 'price', 'livemode' => false],
        ];

        return $this;
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @param  array<string, mixed>  $params
     * @return array{0: string, 1: int, 2: array<string, string>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $path = (string) parse_url((string) $absUrl, PHP_URL_PATH);
        $this->requested[] = $method.' '.$path;

        return [
            json_encode($this->body($path, $params), JSON_THROW_ON_ERROR),
            200,
            ['Content-Type' => 'application/json'],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function body(string $path, array $params): array
    {
        $resource = match ($path) {
            '/v1/subscriptions' => 'subscriptions',
            '/v1/subscription_items' => 'subscription_items',
            default => null,
        };

        if ($resource === null) {
            return ['object' => 'list', 'data' => [], 'has_more' => false];
        }

        $matches = array_values(array_filter(
            $this->resources[$resource],
            fn (array $record): bool => $this->matches($resource, $record, $params),
        ));

        // `starting_after` is honoured rather than ignored: the source pages
        // until a page reports no more, and a fake that repeats its first page
        // would loop until the page limit instead of returning.
        $after = $params['starting_after'] ?? null;

        if (is_string($after)) {
            $seen = array_search($after, array_column($matches, 'id'), true);
            $matches = $seen === false ? [] : array_slice($matches, $seen + 1);
        }

        return ['object' => 'list', 'data' => $matches, 'has_more' => false];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $params
     */
    private function matches(string $resource, array $record, array $params): bool
    {
        return $resource === 'subscriptions'
            ? ($params['customer'] ?? null) === $record['customer']
            : ($params['subscription'] ?? null) === $record['subscription'];
    }
}
