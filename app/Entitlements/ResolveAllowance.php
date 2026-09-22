<?php

declare(strict_types=1);

namespace App\Entitlements;

use DateTimeImmutable;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Resolution\FeatureTypeMismatch;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;

final readonly class ResolveAllowance
{
    /** @param array<string, bool|int|null> $freeAllowances */
    public function __construct(
        private LocalResolver $resolver,
        private array $freeAllowances,
    ) {}

    /**
     * Resolve the package answer with the application's Free-plan floor beneath it.
     *
     * Package state                 Package answer     Effective answer
     * new / none / ended            missing            Free floor
     * stale / catalog mismatch      missing            Free floor
     * active / grace period         explicit value     value raised to the floor
     *
     * Reading `all()` is deliberate: `limit()` collapses both a missing value and an
     * explicit zero to zero before the application can apply its floor.
     */
    public function handle(OwnerReference $owner, string $feature, ?DateTimeImmutable $at = null): bool|int|null
    {
        $values = $this->resolver->for($owner, $at)->all();
        $boolean = $this->resolver->booleanFeature($feature);
        $floor = array_key_exists($feature, $this->freeAllowances)
            ? $this->freeAllowances[$feature]
            : ($boolean ? false : 0);
        $value = array_key_exists($feature, $values)
            ? $values[$feature]
            : ($boolean ? false : 0);

        if ($boolean) {
            throw_if(! is_bool($floor) || ! is_bool($value), FeatureTypeMismatch::class, 'invalid_boolean_grant');

            return $floor || $value;
        }

        throw_if(($floor !== null && (! is_int($floor) || $floor < 0))
            || ($value !== null && (! is_int($value) || $value < 0)), FeatureTypeMismatch::class, 'invalid_numeric_grant');

        return $floor === null || $value === null ? null : max($floor, $value);
    }
}
