<?php

declare(strict_types=1);

namespace App\Billing;

use InvalidArgumentException;

final readonly class Plan
{
    /** @param list<Price> $prices */
    public function __construct(
        public string $key,
        public string $name,
        public array $prices,
    ) {
        throw_if(preg_match('/^[a-z][a-z0-9_-]*$/', $key) !== 1, InvalidArgumentException::class, "Billing plan key [{$key}] is malformed.");

        throw_if(mb_trim($name) === '', InvalidArgumentException::class, "Billing plan [{$key}] must have a name.");

        throw_if($prices === [], InvalidArgumentException::class, "Billing plan [{$key}] must have at least one price.");

        foreach ($prices as $price) {
            if ($price->planKey !== $key) {
                throw new InvalidArgumentException("Billing price [{$price->id}] belongs to another plan.");
            }
        }
    }
}
