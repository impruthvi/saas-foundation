<?php

declare(strict_types=1);

namespace Tests\Support;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * StripeSubscriptionSource builds its own StripeClient from the injected one's
 * credentials, so FakeStripeClient cannot serve the refresh. The SDK's global HTTP
 * client is the seam every client routes through; installed per test and removed after.
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
     * Shaped the way the source validates them: a missing cancel_at or a wrong-kind
     * livemode is rejected as malformed.
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

        // starting_after is honoured: the source pages until a page reports no more, so
        // a fake repeating its first page would loop.
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
