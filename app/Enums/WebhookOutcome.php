<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happened to the latest delivery of a Stripe event.
 */
enum WebhookOutcome: string
{
    case Applied = 'applied';
    case Superseded = 'superseded';
    case Unplaceable = 'unplaceable';
    case Errored = 'errored';

    /**
     * How long a row with this outcome is kept, in days.
     *
     * An event that still needs recovering is kept twice as long as one that
     * has nothing left to do.
     */
    public function retentionDays(): int
    {
        return match ($this) {
            self::Applied, self::Superseded => 90,
            self::Unplaceable, self::Errored => 180,
        };
    }
}
