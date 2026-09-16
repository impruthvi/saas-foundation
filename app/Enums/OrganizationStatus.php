<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an organization sits in its lifecycle.
 *
 * The values land at M1 because M2's invitations need something to refuse
 * against. The transitions that move between them have no caller until the
 * admin console at M6 and are deferred there under D11's filter; this enum is
 * the extension point they will use.
 */
enum OrganizationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /**
     * Whether the organization may be used as a tenant right now.
     */
    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
