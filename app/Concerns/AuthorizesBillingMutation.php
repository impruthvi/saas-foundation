<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Organization;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

trait AuthorizesBillingMutation
{
    abstract protected function policyAbility(): string;

    final public function authorize(TenantContext $tenant): bool
    {
        $organization = $tenant->current();

        if (! $organization instanceof Organization
            || ! ($this->user()?->can($this->policyAbility(), Subscription::class) ?? false)) {
            return false;
        }

        $submittedOrganization = $this->input('organization');

        abort_if(
            is_string($submittedOrganization)
                && $submittedOrganization !== ''
                && $submittedOrganization !== $organization->slug,
            Response::HTTP_CONFLICT,
            __('This organization changed elsewhere. Refresh the billing page and try again.'),
        );

        return true;
    }

    /** @return array<int, mixed> */
    final protected function organizationRules(TenantContext $tenant): array
    {
        $organization = $tenant->current();

        return [
            'required',
            'string',
            'max:255',
            Rule::in($organization instanceof Organization ? [$organization->slug] : []),
        ];
    }
}
