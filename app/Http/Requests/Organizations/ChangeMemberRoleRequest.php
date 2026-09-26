<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Enums\MembershipRole;
use App\Models\Membership;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class ChangeMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $membership = $this->route('membership');

        return $membership instanceof Membership
            && ($this->user()?->can('update', $membership) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Listing ranks explicitly forces new enum cases to be considered before
            // they become assignable.
            'role' => ['required', new Enum(MembershipRole::class), Rule::in([
                MembershipRole::Member->value,
                MembershipRole::Admin->value,
            ])],
        ];
    }

    public function role(): MembershipRole
    {
        return MembershipRole::from($this->string('role')->value());
    }
}
