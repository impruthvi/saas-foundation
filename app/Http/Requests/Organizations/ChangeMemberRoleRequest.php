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
            // Never Owner: ownership is a column, not a rank (D23). The cases
            // are listed rather than taken wholesale so one added later has to
            // be considered here before it is assignable.
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
