<?php

declare(strict_types=1);

namespace App\Actions;

use App\Entitlements\ResolveAllowance;
use App\Exceptions\CrossTenantAccess;
use App\Models\Organization;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Usage\UsageReceipt;
use LogicException;

/**
 * Uses the package connection rather than Eloquent so the project row shares admit()'s
 * transaction. That bypasses model events and BelongsToOrganization, so the tenant is
 * compared before admission and every event-filled column is set explicitly.
 */
final readonly class CreateProject
{
    public function __construct(
        private OwnerLocator $owners,
        private TenantContext $tenant,
        private LocalResolver $resolver,
        private ResolveAllowance $allowances,
    ) {}

    public function handle(Organization $organization, string $name, string $idempotencyToken): Project
    {
        $owner = $this->owners->reference($organization);
        $tenantId = $this->tenant->idOrFail();

        if ($owner->key !== (string) $tenantId) {
            throw CrossTenantAccess::forModel(Project::class, $organization->id, $tenantId);
        }

        $receipt = $this->resolver->usageStore()->admit(
            $owner,
            'projects',
            1,
            'project:create:'.hash('sha256', $idempotencyToken),
            $this->allowances,
            function (Connection $database, UsageReceipt $receipt) use ($organization, $name): void {
                $now = Date::now();

                $database->table('projects')->insert([
                    'organization_id' => $organization->id,
                    'name' => $name,
                    'usage_receipt_id' => $receipt->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            },
        );

        return Project::query()->where('usage_receipt_id', $receipt->id)->first()
            ?? throw new LogicException('An admitted project could not be recovered from its usage receipt.');
    }
}
