<?php

declare(strict_types=1);

namespace App\Entitlements;

use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;

/**
 * The Stripe events that asked for an owner's refresh at a given moment.
 *
 * The package records these receipts but offers no way to read them, so this is
 * the one place that reads a package table directly. It uses the package's own
 * owner key, and an architecture test keeps every other class out of those
 * tables. A package upgrade that reshapes receipts has to be read here first.
 */
final readonly class RefreshReceipts
{
    public function __construct(private NativeStateStore $store) {}

    /**
     * Event ids whose refresh request landed in this second.
     *
     * Receipts carry whole seconds and no sequence, and checkout emits two
     * refresh-requesting events together, so this is a list, not an answer.
     *
     * @return list<string>
     */
    public function receivedAt(OwnerReference $owner, int $receivedAt): array
    {
        /** @var list<string> */
        return $this->store->database($owner)
            ->table('cashier_entitlement_receipts')
            ->where('owner_id', $this->store->ownerId($owner))
            ->where('received_at', $receivedAt)
            ->orderBy('event_id')
            ->pluck('event_id')
            ->all();
    }
}
