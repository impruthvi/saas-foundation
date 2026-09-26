<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an organization's allowance for a feature came from.
 */
enum AllowanceSource: string
{
    case Package = 'package';
    case Floor = 'floor';
}
