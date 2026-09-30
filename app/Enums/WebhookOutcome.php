<?php

declare(strict_types=1);

namespace App\Enums;

enum WebhookOutcome: string
{
    case Applied = 'applied';
    case Superseded = 'superseded';
    case Unplaceable = 'unplaceable';
    case Errored = 'errored';
    case Replayed = 'replayed';
    case Refused = 'refused';

    /** Only an event that never applied can be replayed. */
    public function isReplayable(): bool
    {
        return $this === self::Unplaceable || $this === self::Errored;
    }

    /**
     * Unrecovered events are kept twice as long; replayed and refused keep the window
     * of the problem they came from.
     */
    public function retentionDays(): int
    {
        return match ($this) {
            self::Applied, self::Superseded => 90,
            self::Unplaceable, self::Errored, self::Replayed, self::Refused => 180,
        };
    }
}
