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
