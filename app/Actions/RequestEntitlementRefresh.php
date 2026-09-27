<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Impruthvi\CashierEntitlements\Reconciliation\RefreshManager;

final readonly class RequestEntitlementRefresh
{
    public function __construct(
        private TenantContext $tenant,
        private RefreshManager $refreshes,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Organization $organization): void
    {
        $this->tenant->runFor($organization, function () use ($organization): void {
            $this->refreshes->request($organization);

            $this->audit->handle($organization->id, AuditAction::EntitlementRefreshRequested, $organization);
        });
    }
}
