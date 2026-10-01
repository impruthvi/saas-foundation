<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Organization;
use App\Models\Subscription;
use App\Tenancy\TenantContext;

trait AuthorizesBillingMutation
{
    use NamesItsOrganization;

    abstract protected function policyAbility(): string;

    final public function authorize(TenantContext $tenant): bool
    {
        $organization = $tenant->current();

        return $organization instanceof Organization
            && ($this->user()?->can($this->policyAbility(), Subscription::class) ?? false);
    }
}
