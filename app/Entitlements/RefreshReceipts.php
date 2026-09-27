<?php

declare(strict_types=1);

namespace App\Entitlements;

use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;

/**
 * The package offers no reader for its receipts, so this is the one class allowed to
 * read a package table directly; an architecture test keeps everything else out.
 */
final readonly class RefreshReceipts
{
    public function __construct(private NativeStateStore $store) {}

    /**
     * Receipts have whole-second precision and no sequence, and checkout emits two
     * events together, so this returns a list.
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
