<?php

declare(strict_types=1);

namespace App\Concerns;

/**
 * A tenant-scoped write names the organization its page was rendered for. Only presence
 * is validated here: RejectStaleOrganizationMutation compares it against the session's
 * organization before the request reaches its route.
 */
trait NamesItsOrganization
{
    /** @return list<string> */
    protected function organizationRules(): array
    {
        return ['required', 'string', 'max:255'];
    }
}
