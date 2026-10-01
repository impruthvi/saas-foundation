<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncStripeCustomerContact implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $organizationId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $organization = Organization::query()->with('owner')->find($this->organizationId);

        if (! $organization instanceof Organization || ! $organization->hasStripeId()) {
            return;
        }

        $organization->updateStripeCustomer([
            'name' => $organization->name,
            'email' => $organization->stripeEmail(),
        ]);
    }
}
