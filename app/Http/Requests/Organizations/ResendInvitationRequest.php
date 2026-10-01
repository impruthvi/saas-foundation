<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Concerns\NamesItsOrganization;
use App\Models\Invitation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ResendInvitationRequest extends FormRequest
{
    use NamesItsOrganization;

    public function authorize(): bool
    {
        $invitation = $this->route('invitation');

        return $invitation instanceof Invitation
            && ($this->user()?->can('update', $invitation) ?? false);
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
