<?php

declare(strict_types=1);

use App\Actions\GrantEntitlementOverride;
use App\Actions\RevokeEntitlementOverride;
use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\Operator;
use App\Models\Organization;
use App\Models\User;
use App\Operations\InspectEntitlements;
use App\Tenancy\TenantContext;
use Impruthvi\CashierEntitlements\Overrides\NativeOverrides;

it('grants a time-bound project allowance and audits the operator', function (): void {
    $this->freezeTime();
    $organization = Organization::factory()->create();
    $operator = User::factory()->create();
    Operator::factory()->create(['user_id' => $operator->id]);

    $grantId = resolve(GrantEntitlementOverride::class)->handle(
        $organization,
        $operator,
        'projects',
        5,
        'Support grant for launch',
        now()->addDays(30)->toDateTimeImmutable(),
    );

    $inspection = resolve(InspectEntitlements::class)->for($organization);
    $projects = collect($inspection['features'])->firstWhere('feature', 'projects');
    $audit = resolve(TenantContext::class)->runFor($organization, fn (): AuditEvent => AuditEvent::query()->sole());

    expect($projects)->toMatchArray(['allowance' => 5, 'source' => 'override'])
        ->and($grantId)->toBeGreaterThan(0)
        ->and($audit->action)->toBe(AuditAction::EntitlementOverrideGranted)
        ->and($audit->actor_id)->toBe($operator->id)
        ->and($audit->context)->toMatchArray(['grant_id' => $grantId, 'reason' => 'Support grant for launch']);

    $this->travel(31)->days();

    $afterExpiry = resolve(InspectEntitlements::class)->for($organization);
    expect(collect($afterExpiry['features'])->firstWhere('feature', 'projects'))->toMatchArray(['allowance' => 2, 'source' => 'floor'])
        ->and($afterExpiry['overrides'])->toBe([]);
});

it('revokes a grant with an append-only entry and restores the underlying allowance', function (): void {
    $this->freezeTime();
    $organization = Organization::factory()->create();
    $operator = User::factory()->create();
    Operator::factory()->create(['user_id' => $operator->id]);
    $grantId = resolve(GrantEntitlementOverride::class)->handle(
        $organization, $operator, 'projects', 5, 'Temporary support', now()->addMonth()->toDateTimeImmutable(),
    );

    $revokeId = resolve(RevokeEntitlementOverride::class)->handle($organization, $operator, $grantId, 'Support period ended');

    $projects = collect(resolve(InspectEntitlements::class)->for($organization)['features'])->firstWhere('feature', 'projects');
    $history = resolve(NativeOverrides::class)->history(organizationEntitlementOwner($organization));
    $audit = resolve(TenantContext::class)->runFor(
        $organization,
        fn (): AuditEvent => AuditEvent::query()->where('action', AuditAction::EntitlementOverrideRevoked)->sole(),
    );

    expect($projects)->toMatchArray(['allowance' => 2, 'source' => 'floor'])
        ->and($history)->toHaveCount(2)
        ->and($history[1])->toMatchArray(['id' => $revokeId, 'kind' => 'revoke', 'target_id' => $grantId])
        ->and($audit->actor_id)->toBe($operator->id)
        ->and($audit->context)->toMatchArray(['grant_id' => $grantId, 'reason' => 'Support period ended']);
});

it('refuses a grant from a user who is not an operator', function (): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    resolve(GrantEntitlementOverride::class)->handle(
        $organization, $user, 'projects', 5, 'Unauthorized', now()->addDay()->toDateTimeImmutable(),
    );
})->throws(Illuminate\Auth\Access\AuthorizationException::class);

it('refuses a revocation from a user who is not an operator', function (): void {
    $organization = Organization::factory()->create();
    $operator = User::factory()->create();
    Operator::factory()->create(['user_id' => $operator->id]);
    $grantId = resolve(GrantEntitlementOverride::class)->handle(
        $organization, $operator, 'projects', 5, 'Temporary support', now()->addDay()->toDateTimeImmutable(),
    );

    resolve(RevokeEntitlementOverride::class)->handle($organization, User::factory()->create(), $grantId, 'Unauthorized');
})->throws(Illuminate\Auth\Access\AuthorizationException::class);

it('refuses to revoke a grant belonging to another organization', function (): void {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $operator = User::factory()->create();
    Operator::factory()->create(['user_id' => $operator->id]);
    $grantId = resolve(GrantEntitlementOverride::class)->handle(
        $first, $operator, 'projects', 5, 'For first organization', now()->addDay()->toDateTimeImmutable(),
    );

    resolve(RevokeEntitlementOverride::class)->handle($second, $operator, $grantId, 'Wrong organization');
})->throws(InvalidArgumentException::class, 'override_grant_not_found');
