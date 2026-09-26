<?php

declare(strict_types=1);

namespace App\Actions;

use App\Billing\BillingFacts;
use App\Enums\AuditAction;
use App\Exceptions\Billing\SubscriptionNotCancelled;
use App\Models\Organization;
use App\Models\Subscription;

final readonly class ResumeSubscription
{
    public function __construct(
        private BillingFacts $billing,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Organization $organization): Subscription
    {
        $subscription = $this->billing->currentSubscription($organization);

        if (! $subscription instanceof Subscription || ! $subscription->onGracePeriod()) {
            throw SubscriptionNotCancelled::for($organization);
        }

        $subscription->setRelation('owner', $organization);

        $subscription->resume();

        $this->audit->handle($organization->id, AuditAction::SubscriptionResumed, $subscription, [
            'ends_at' => $subscription->ends_at?->toIso8601String(),
        ]);

        return $subscription;
    }
}
