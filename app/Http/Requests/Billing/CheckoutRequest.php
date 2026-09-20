<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Billing\PlanCatalog;
use App\Billing\Price;
use App\Models\Organization;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final class CheckoutRequest extends FormRequest
{
    public function authorize(TenantContext $tenant): bool
    {
        $organization = $tenant->current();

        if (! $organization instanceof Organization
            || ! ($this->user()?->can('create', Subscription::class) ?? false)) {
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

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(PlanCatalog $catalog, TenantContext $tenant): array
    {
        $organization = $tenant->current();

        return [
            'organization' => [
                'required',
                'string',
                'max:255',
                Rule::in($organization instanceof Organization ? [$organization->slug] : []),
            ],
            'price' => ['required', 'string', Rule::in($catalog->priceIds())],
        ];
    }

    public function price(PlanCatalog $catalog): Price
    {
        $price = $catalog->findPrice($this->string('price')->value());

        throw_unless($price instanceof Price, LogicException::class, 'A validated billing price was not found.');

        return $price;
    }
}
