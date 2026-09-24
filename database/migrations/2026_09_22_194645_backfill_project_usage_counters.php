<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Usage\MeterPeriods;
use Impruthvi\CashierEntitlements\Usage\UsagePeriod;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $connectionName = $connection->getName();

        throw_unless(is_string($connectionName), LogicException::class, 'The entitlement backfill requires a named database connection.');

        $store = new NativeStateStore($connection);
        $periods = new MeterPeriods(['projects' => 'lifetime']);
        $providerContext = Config::string('cashier-entitlements.provider_context');
        $liveMode = Config::boolean('cashier-entitlements.live_mode');

        $connection->table('organizations')
            ->leftJoin('projects', 'projects.organization_id', '=', 'organizations.id')
            ->select('organizations.id as organization_id')
            ->selectRaw('count(projects.id) as project_count')
            ->groupBy('organizations.id')
            ->chunkById(500, function (Collection $organizations) use ($connection, $connectionName, $store, $periods, $providerContext, $liveMode): void {
                $counters = [];

                foreach ($organizations as $organization) {
                    $owner = new OwnerReference(
                        type: 'organization',
                        key: (string) $organization->organization_id,
                        connection: $connectionName,
                        providerContext: $providerContext,
                        liveMode: $liveMode,
                    );
                    $ownerId = $store->ownerId($owner);
                    $period = $periods->period('projects', new DateTimeImmutable('@0'), $connection, $ownerId);

                    $counters[] = [
                        'id' => $this->counterId($ownerId, $period),
                        'total' => (int) $organization->project_count,
                    ];
                }

                $connection->table('cashier_entitlement_usage_counters')->upsert(
                    $counters,
                    ['id'],
                    ['total'],
                );
            }, 'organizations.id', 'organization_id');
    }

    public function down(): void
    {
        // Usage may advance after deployment, so a rollback cannot safely restore earlier totals.
    }

    private function counterId(string $ownerId, UsagePeriod $period): string
    {
        return hash('sha256', json_encode([
            $ownerId,
            'projects',
            $period->start->format('U.u'),
            $period->end->format('U.u'),
        ], JSON_THROW_ON_ERROR));
    }
};
