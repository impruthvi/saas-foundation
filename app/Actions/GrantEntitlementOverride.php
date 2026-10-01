<?php

declare(strict_types=1);

namespace App\Actions;

use App\Audit\AuditActor;
use App\Contracts\Operators;
use App\Enums\AuditAction;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Impruthvi\CashierEntitlements\Overrides\NativeOverrides;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use InvalidArgumentException;

final readonly class GrantEntitlementOverride
{
    public function __construct(
        private Operators $operators,
        private TenantContext $tenant,
        private OwnerLocator $owners,
        private LocalResolver $resolver,
        private NativeOverrides $overrides,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Organization $organization, User $operator, string $feature, bool|int|null $allowance, string $reason, DateTimeImmutable $expiresAt): int
    {
        throw_unless($this->operators->isOperator($operator), AuthorizationException::class, 'Only an operator can grant an entitlement override.');
        throw_unless(in_array($feature, $this->resolver->catalogFeatures(), true), InvalidArgumentException::class, 'Unknown entitlement feature.');

        return AuditActor::runAs(AuditActor::user($operator), fn (): int => DB::transaction(
            fn (): int => $this->tenant->runFor($organization, function () use ($organization, $operator, $feature, $allowance, $reason, $expiresAt): int {
                $grantId = $this->overrides->grant(
                    $this->owners->reference($organization),
                    $feature,
                    $allowance,
                    mb_trim($reason),
                    "operator:{$operator->id}",
                    Date::now()->toDateTimeImmutable(),
                    $expiresAt,
                );

                $this->audit->handle($organization->id, AuditAction::EntitlementOverrideGranted, $organization, [
                    'grant_id' => $grantId,
                    'feature' => $feature,
                    'allowance' => $allowance,
                    'reason' => mb_trim($reason),
                    'expires_at' => $expiresAt->format(DATE_ATOM),
                ]);

                return $grantId;
            }),
        ));
    }
}
