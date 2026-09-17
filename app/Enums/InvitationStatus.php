<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an invitation sits between being issued and being over.
 *
 *            issue / re-issue
 *                  │
 *                  ▼
 *             ┌─────────┐  accept   ┌──────────┐
 *             │ Pending │──────────►│ Accepted │  terminal
 *             └─────────┘           └──────────┘
 *              │   │   │
 *      decline │   │   │ revoke
 *              ▼   │   ▼
 *      ┌──────────┐│┌─────────┐
 *      │ Declined │││ Revoked │     both terminal for this issuance;
 *      └──────────┘│└─────────┘     inviting the address again rotates
 *                  │                the row back to Pending
 *                  │ expires_at passes
 *                  ▼
 *            (no case — expiry is derived)
 *
 * There is deliberately no Expired case. Expiry is `expires_at < now()`, which
 * is true the moment it is true; a stored status would need a sweeper to make a
 * fact about the clock into a fact about a row, and would give one fact two
 * homes — the failure D23 exists to prevent, in a smaller costume.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Revoked = 'revoked';

    /**
     * Whether this status still allows the invitation to be taken.
     *
     * Says nothing about expiry, which is a question for the timestamp.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
