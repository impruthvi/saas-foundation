<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Carried between processes as an exit status, so the values are small integers.
 */
enum Outcome: int
{
    case Succeeded = 0;
    case Refused = 1;
    case Failed = 2;
}
