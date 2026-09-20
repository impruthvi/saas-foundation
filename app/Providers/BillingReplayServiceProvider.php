<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Impruthvi\CashierDunning\CashierDunning;

final class BillingReplayServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        CashierDunning::createBillableUsing(function (): Organization {
            $owner = User::factory()->create([
                'email' => 'replay@example.test',
            ]);

            return Organization::factory()
                ->for($owner, 'owner')
                ->create([
                    'name' => 'Replay Organization',
                    'stripe_id' => 'cus_replay1',
                ]);
        });
    }
}
