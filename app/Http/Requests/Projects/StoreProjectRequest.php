<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Concerns\NamesItsOrganization;
use App\Models\Organization;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectRequest extends FormRequest
{
    use NamesItsOrganization;

    public function authorize(TenantContext $tenant): bool
    {
        $organization = $tenant->current();

        return $organization instanceof Organization
            && ($this->user()?->can('create', Project::class) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'organization' => $this->organizationRules(),
            'name' => ['required', 'string', 'max:255'],
            'idempotency_token' => ['required', 'string', 'max:255'],
        ];
    }

    public function projectName(): string
    {
        return $this->string('name')->value();
    }

    public function idempotencyToken(): string
    {
        return $this->string('idempotency_token')->value();
    }
}
