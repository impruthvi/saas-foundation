<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * `System` marks an act with no known actor.
 */
enum AuditSource: string
{
    case Web = 'web';
    case Console = 'console';
    case Stripe = 'stripe';
    case System = 'system';
}
