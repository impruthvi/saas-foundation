<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Models\Organization;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class StoreProjectRequest extends FormRequest
{
    public function authorize(TenantContext $tenant): bool
    {
        $organization = $tenant->current();

        if (! $organization instanceof Organization
            || ! ($this->user()?->can('create', Project::class) ?? false)) {
            return false;
        }

        $submittedOrganization = $this->input('organization');

        abort_if(
            is_string($submittedOrganization)
                && $submittedOrganization !== ''
                && $submittedOrganization !== $organization->slug,
            Response::HTTP_CONFLICT,
            __('This organization changed elsewhere. Refresh the projects page and try again.'),
        );

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(TenantContext $tenant): array
    {
        $organization = $tenant->current();

        return [
            'organization' => [
                'required',
                'string',
                'max:255',
                Rule::in($organization instanceof Organization ? [$organization->slug] : []),
            ],
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
