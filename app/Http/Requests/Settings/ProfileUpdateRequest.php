<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return $this->profileRules($this->user()->id);
    }
}
