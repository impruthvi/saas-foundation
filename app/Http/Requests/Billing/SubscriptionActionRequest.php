<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Concerns\AuthorizesBillingMutation;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class SubscriptionActionRequest extends FormRequest
{
    use AuthorizesBillingMutation;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(TenantContext $tenant): array
    {
        return [
            'organization' => $this->organizationRules($tenant),
        ];
    }

    protected function policyAbility(): string
    {
        return 'manage';
    }
}
