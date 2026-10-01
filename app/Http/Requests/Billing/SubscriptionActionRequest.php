<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Concerns\AuthorizesBillingMutation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class SubscriptionActionRequest extends FormRequest
{
    use AuthorizesBillingMutation;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'organization' => $this->organizationRules(),
        ];
    }

    protected function policyAbility(): string
    {
        return 'manage';
    }
}
