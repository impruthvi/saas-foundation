<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an audited act came from.
 *
 * `System` is what an act records when nothing said who was acting, which is
 * itself worth seeing in the log.
 */
enum AuditSource: string
{
    case Web = 'web';
    case Console = 'console';
    case Stripe = 'stripe';
    case System = 'system';
}
