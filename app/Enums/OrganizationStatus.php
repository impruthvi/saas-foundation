<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
