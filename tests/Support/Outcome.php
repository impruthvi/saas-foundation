<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * What one contender's attempt came to.
 *
 * Carried between processes as an exit status, so the values are small integers
 * rather than anything a child could serialize.
 */
enum Outcome: int
{
    case Succeeded = 0;
    case Refused = 1;
    case Failed = 2;
}
