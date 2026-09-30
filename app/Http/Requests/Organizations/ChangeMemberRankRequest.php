<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Concerns\RankValidationRules;
use App\Models\Membership;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ChangeMemberRankRequest extends FormRequest
{
    use RankValidationRules;

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
            'role' => $this->rankRules(),
        ];
    }
}
