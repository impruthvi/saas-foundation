<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use Impruthvi\CashierEntitlements\Commands\ReconcileCommand;

/**
 * Runs the package's reconcile command with the owner's organization resolved.
 *
 * `entitlements:reconcile` never goes near the queue: `--apply` calls the
 * refresh manager in process, and the dry run reads the same subscriptions
 * through the reconciler. Both read a tenant-scoped relation, so a job pipe
 * alone leaves every console invocation broken.
 *
 * This replaces the package's registration by name rather than extending it,
 * because the package command is final, and it delegates rather than
 * reimplementing so the report shape stays the package's to define.
 *
 * `--all` is refused: it audits every owner in one in-package loop, which
 * cannot be given one organization from out here.
 */
final class ReconcileEntitlementsCommand extends Command
{
    /** @var string */
    protected $signature = 'entitlements:reconcile
        {--owner-type= : The registered morph alias of the owner}
        {--owner= : The owner key}
        {--all}
        {--test-clock=}
        {--dry-run}
        {--apply}
        {--json}';

    /** @var string */
    protected $description = 'Compare Stripe with Cashier, or explicitly apply native access for one owner';

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

    /**
     * The organization to resolve, or null to let the package refuse the input.
     */
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
