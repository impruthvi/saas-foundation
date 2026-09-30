<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Enums\MembershipRank;
use App\Models\Membership;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class ChangeMemberRankRequest extends FormRequest
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
