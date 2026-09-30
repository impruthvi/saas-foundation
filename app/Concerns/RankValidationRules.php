<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\MembershipRank;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;

/**
 * @phpstan-require-extends FormRequest
 */
trait RankValidationRules
{
    public function rank(): MembershipRank
    {
        return MembershipRank::from($this->string('role')->value());
    }

    /**
     * Listing ranks explicitly forces a new enum case to be considered before it becomes
     * assignable.
     *
     * @return array<int, Enum|In|string>
     */
    protected function rankRules(): array
    {
        return ['required', new Enum(MembershipRank::class), Rule::in([
            MembershipRank::Member->value,
            MembershipRank::Admin->value,
        ])];
    }
}
