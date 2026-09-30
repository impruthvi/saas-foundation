<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Enums\MembershipRank;
use App\Models\Invitation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class InviteMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Invitation::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            // Listing ranks explicitly forces new enum cases to be considered before
            // they become invitable.
            'role' => ['required', new Enum(MembershipRank::class), Rule::in([
                MembershipRank::Member->value,
                MembershipRank::Admin->value,
            ])],
        ];
    }

    public function rank(): MembershipRank
    {
        return MembershipRank::from($this->string('role')->value());
    }
}
