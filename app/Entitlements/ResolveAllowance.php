<?php

declare(strict_types=1);

namespace App\Entitlements;

use App\Enums\AllowanceSource;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Resolution\FeatureTypeMismatch;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Usage\AdmissionResolver;

final readonly class ResolveAllowance implements AdmissionResolver
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
        return $this->explain($owner, $feature, $at)['value'];
    }

    /**
     * The answer, and whether the package or the Free-plan floor supplied it.
     *
     * The floor supplies it when the package has no value for the feature, or a
     * lower one. Equal values are credited to the package, because that is the
     * answer an operator would see change after a refresh.
     *
     * @return array{value: bool|int|null, source: AllowanceSource}
     */
    public function explain(OwnerReference $owner, string $feature, ?DateTimeImmutable $at = null): array
    {
        $values = $this->resolver->for($owner, $at)->all();
        $fromPackage = array_key_exists($feature, $values);
        $boolean = $this->resolver->booleanFeature($feature);
        $floor = array_key_exists($feature, $this->freeAllowances)
            ? $this->freeAllowances[$feature]
            : ($boolean ? false : 0);
        $value = array_key_exists($feature, $values)
            ? $values[$feature]
            : ($boolean ? false : 0);

        if ($boolean) {
            throw_if(! is_bool($floor) || ! is_bool($value), FeatureTypeMismatch::class, 'invalid_boolean_grant');

            $answer = $floor || $value;

            return ['value' => $answer, 'source' => $fromPackage && $value === $answer ? AllowanceSource::Package : AllowanceSource::Floor];
        }

        throw_if(($floor !== null && (! is_int($floor) || $floor < 0))
            || ($value !== null && (! is_int($value) || $value < 0)), FeatureTypeMismatch::class, 'invalid_numeric_grant');

        $answer = $floor === null || $value === null ? null : max($floor, $value);

        return ['value' => $answer, 'source' => $fromPackage && $value === $answer ? AllowanceSource::Package : AllowanceSource::Floor];
    }

    public function assertConnection(OwnerReference $owner, Connection $connection): void
    {
        $this->resolver->assertConnection($owner, $connection);
    }

    public function limit(OwnerReference $owner, string $feature, DateTimeImmutable $at): ?int
    {
        $allowance = $this->handle($owner, $feature, $at);

        throw_if(is_bool($allowance), FeatureTypeMismatch::class, 'admission_requires_numeric');

        return $allowance;
    }
}
