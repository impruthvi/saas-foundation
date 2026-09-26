<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use Impruthvi\CashierEntitlements\Commands\ReconcileCommand;

/**
 * Wraps the package command with the owner's organization resolved, because --apply and
 * the dry run both read a tenant-scoped relation. Replaces rather than extends it
 * because the package command is final. --all is refused: its loop cannot be given one
 * organization from here.
 */
#[Description('Compare Stripe with Cashier, or explicitly apply native access for one owner')]
#[Signature('entitlements:reconcile
        {--owner-type= : The registered morph alias of the owner}
        {--owner= : The owner key}
        {--all}
        {--test-clock=}
        {--dry-run}
        {--apply}
        {--json}')]
final class ReconcileEntitlementsCommand extends Command
{
    public function handle(TenantContext $tenant): int
    {
        if ($this->option('all') && ! $this->option('apply')) {
            return $this->refuseUnscopedAudit();
        }

        $organizationId = $this->organizationScope();

        return $organizationId === null
            ? $this->delegate()
            : $tenant->runForId($organizationId, fn (): int => $this->delegate());
    }

    private function organizationScope(): ?int
    {
        $alias = $this->option('owner-type');
        $key = $this->option('owner');

        if (! is_string($alias) || Relation::getMorphedModel($alias) !== Organization::class) {
            return null;
        }

        return is_string($key) && ctype_digit($key) ? (int) $key : null;
    }

    private function delegate(): int
    {
        $command = new ReconcileCommand();
        $command->setLaravel($this->laravel);
        $command->setApplication($this->getApplication());

        return $command->run($this->input, $this->output);
    }

    private function refuseUnscopedAudit(): int
    {
        $this->line(json_encode([
            'schema_version' => 1,
            'mode' => 'dry-run',
            'complete' => false,
            'proposed_decision' => null,
            'differences' => [],
            'errors' => ['owner_scope_required'],
            'exit_code' => 2,
        ], JSON_THROW_ON_ERROR | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

        return 2;
    }
}
