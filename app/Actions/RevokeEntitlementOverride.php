<?php

declare(strict_types=1);

namespace App\Actions;

use App\Audit\AuditActor;
use App\Contracts\Operators;
use App\Enums\AuditAction;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Impruthvi\CashierEntitlements\Overrides\NativeOverrides;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;

final readonly class RevokeEntitlementOverride
{
    public function __construct(
        private Operators $operators,
        private TenantContext $tenant,
        private OwnerLocator $owners,
        private NativeOverrides $overrides,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Organization $organization, User $operator, int $grantId, string $reason): int
    {
        throw_unless($this->operators->isOperator($operator), AuthorizationException::class, 'Only an operator can revoke an entitlement override.');

        return AuditActor::runAs(AuditActor::user($operator), fn (): int => DB::transaction(
            fn (): int => $this->tenant->runFor($organization, function () use ($organization, $operator, $grantId, $reason): int {
                $revokeId = $this->overrides->revoke(
                    $this->owners->reference($organization),
                    $grantId,
                    mb_trim($reason),
                    "operator:{$operator->id}",
                    Date::now()->toDateTimeImmutable(),
                );

                $this->audit->handle($organization->id, AuditAction::EntitlementOverrideRevoked, $organization, [
                    'grant_id' => $grantId,
                    'revoke_id' => $revokeId,
                    'reason' => mb_trim($reason),
                ]);

                return $revokeId;
            }),
        ));
    }
}
