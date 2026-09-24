<?php

declare(strict_types=1);

return [
    // Opt in to native application and background refresh. Dry-run remains read-only.
    //
    // Off for the dunning replay, and only there. The refresh this would request
    // is dispatched after commit, and the replay rolls back, so the harness
    // rightly refuses to report success over work it could never observe. The
    // replay answers whether subscription facts converge; whether the resolved
    // entitlement converges is asserted after it, outside its transaction, by
    // tests/Feature/Entitlements/PostReplayResolutionTest.php.
    'enabled' => env('CASHIER_ENTITLEMENTS_ENABLED', true),
    'provider_context' => 'platform',
    'live_mode' => false,
    'subscription_type' => 'default',
    'connection' => null,
    // Choose exactly one: ['max_stale_age' => 3600] or ['retain_last_known' => true].
    'freshness' => ['max_stale_age' => 3600],
    // Usage period per numeric feature: 'lifetime', 'calendar_day', 'calendar_month' or 'billing:<price_id>'.
    'meters' => ['projects' => 'lifetime'],
    // Consult the audited override ledger during local resolution. Costs one extra query per resolve.
    'overrides' => false,
    // Where applied entitlements are projected: 'native' only, or also 'masterix'.
    'driver' => 'native',
    // Application plan key => Masterix plan key. Required for every plan the catalog can resolve.
    'masterix' => ['plans' => []],
    // Scheduled convergence. Both entries need 'owner_type'; a null expression schedules nothing.
    // Cron expressions only, so the schedule is explicit rather than inferred from a method name.
    'schedule' => [
        'owner_type' => 'organization',
        'sweep' => '*/15 * * * *',
        'sweep_limit' => 100,
        'stale_after' => 1800,
        'recover' => '*/5 * * * *',
    ],
];
