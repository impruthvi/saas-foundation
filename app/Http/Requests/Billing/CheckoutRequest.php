<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Billing\PlanCatalog;
use App\Billing\Price;
use App\Concerns\AuthorizesBillingMutation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

final class CheckoutRequest extends FormRequest
{
    use AuthorizesBillingMutation;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(PlanCatalog $catalog): array
    {
        return [
            'organization' => $this->organizationRules(),
            'price' => ['required', 'string', Rule::in($catalog->priceIds())],
        ];
    }

    public function price(PlanCatalog $catalog): Price
    {
        $price = $catalog->findPrice($this->string('price')->value());

        throw_unless($price instanceof Price, LogicException::class, 'A validated billing price was not found.');

        return $price;
    }

    protected function policyAbility(): string
    {
        return 'create';
    }
}
