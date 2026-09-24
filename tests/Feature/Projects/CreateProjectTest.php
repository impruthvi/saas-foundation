<?php

declare(strict_types=1);

use App\Actions\CreateProject;
use App\Exceptions\CrossTenantAccess;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Usage\LimitExceeded;

it('creates a project and its lifetime usage in one admitted operation', function (): void {
    [$organization] = organizationOwnedBySomeone();

    $project = resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Project => resolve(CreateProject::class)->handle($organization, 'First project', 'request-one'),
    );

    $owner = resolve(OwnerLocator::class)->reference($organization);

    expect($project->organization_id)->toBe($organization->id)
        ->and($project->name)->toBe('First project')
        ->and($project->usage_receipt_id)->not->toBeNull()
        ->and(resolve(LocalResolver::class)->usageStore()->usage($owner, 'projects', now()->toDateTimeImmutable()))->toBe(1);
});

it('enforces the Free-plan floor inside admission', function (): void {
    [$organization] = organizationOwnedBySomeone();

    resolve(TenantContext::class)->runFor($organization, function () use ($organization): void {
        $create = resolve(CreateProject::class);

        $create->handle($organization, 'First project', 'request-one');
        $create->handle($organization, 'Second project', 'request-two');

        expect(fn (): Project => $create->handle($organization, 'Third project', 'request-three'))
            ->toThrow(LimitExceeded::class)
            ->and(Project::query()->pluck('name')->all())->toBe(['First project', 'Second project']);
    });
});

it('returns the original project when a token is retried with a different name', function (): void {
    [$organization] = organizationOwnedBySomeone();

    [$first, $retry] = resolve(TenantContext::class)->runFor($organization, function () use ($organization): array {
        $create = resolve(CreateProject::class);

        return [
            $create->handle($organization, 'Original name', 'same-request'),
            $create->handle($organization, 'Changed name', 'same-request'),
        ];
    });

    $owner = resolve(OwnerLocator::class)->reference($organization);

    expect($retry->is($first))->toBeTrue()
        ->and($retry->name)->toBe('Original name')
        ->and(resolve(TenantContext::class)->runFor($organization, fn (): int => Project::query()->count()))->toBe(1)
        ->and(resolve(LocalResolver::class)->usageStore()->usage($owner, 'projects', now()->toDateTimeImmutable()))->toBe(1);
});

it('returns limit usage and the cheapest upgrade plan from the endpoint', function (): void {
    [$organization, $owner] = organizationOwnedBySomeone();
    $payload = fn (string $name, string $token): array => [
        'organization' => $organization->slug,
        'name' => $name,
        'idempotency_token' => $token,
    ];

    $this->actingAs($owner)
        ->withSession([ResolveTenantContext::SESSION_KEY => $organization->id])
        ->post(route('projects.store'), $payload('First project', 'request-one'))
        ->assertStatus(303);

    $this->post(route('projects.store'), $payload('Second project', 'request-two'))
        ->assertStatus(303);

    $this->postJson(route('projects.store'), $payload('Third project', 'request-three'))
        ->assertUnprocessable()
        ->assertJsonPath('limit', 2)
        ->assertJsonPath('usage', 2)
        ->assertJsonPath('upgradePlan', 'Pro')
        ->assertJsonValidationErrors('project');

    expect(resolve(TenantContext::class)->runFor($organization, fn (): int => Project::query()->count()))->toBe(2);
});

it('refuses another organization before admitting usage', function (): void {
    [$first] = organizationOwnedBySomeone('First');
    [$second] = organizationOwnedBySomeone('Second');

    expect(fn (): Project => resolve(TenantContext::class)->runFor(
        $second,
        fn (): Project => resolve(CreateProject::class)->handle($first, 'Crossed project', 'request-one'),
    ))->toThrow(CrossTenantAccess::class)
        ->and(acrossEveryOwner(fn (): int => DB::table('cashier_entitlement_usage_events')->count()))->toBe(0)
        ->and(DB::table('projects')->whereIn('organization_id', [$first->id, $second->id])->count())->toBe(0);
});
