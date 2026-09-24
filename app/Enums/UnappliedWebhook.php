<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a Stripe event was acknowledged without being applied.
 */
enum UnappliedWebhook: string
{
    case Unplaceable = 'unplaceable';
    case Superseded = 'superseded';
}
