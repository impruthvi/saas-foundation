<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Concerns\RankValidationRules;
use App\Models\Invitation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class InviteMemberRequest extends FormRequest
{
    use RankValidationRules;

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
            'role' => $this->rankRules(),
        ];
    }
}
