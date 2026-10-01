<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Concerns\NamesItsOrganization;
use App\Models\Membership;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class RemoveMemberRequest extends FormRequest
{
    use NamesItsOrganization;

    public function authorize(): bool
    {
        $membership = $this->route('membership');

        return $membership instanceof Membership
            && ($this->user()?->can('delete', $membership) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'organization' => $this->organizationRules(),
        ];
    }
}
