<?php

declare(strict_types=1);

use App\Actions\SeedDemoJourney;
use App\Billing\PlanCatalog;
use App\Enums\AuditAction;
use App\Enums\AuditSource;
use App\Enums\MembershipRank;
use App\Enums\MembershipStatus;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;

function demoOrganization(): Organization
{
    $owner = User::query()->where('email', SeedDemoJourney::OWNER_EMAIL)->firstOrFail();

    return Organization::query()->where('owner_id', $owner->id)->where('personal', true)->firstOrFail();
}

it('refuses outside local and testing and writes nothing', function (string $environment): void {
    $original = app()->environment();
    app()->detectEnvironment(fn (): string => $environment);

    try {
        $this->artisan('saas:demo')
            ->expectsOutputToContain('runs only in local or testing')
            ->assertFailed();
    } finally {
        app()->detectEnvironment(fn (): string => $original);
    }

    expect(User::query()->count())->toBe(0);
})->with(['production', 'staging']);

it('seeds the journey up to the Free plan limit', function (): void {
    $exitCode = Artisan::call('saas:demo');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain(SeedDemoJourney::OWNER_EMAIL, SeedDemoJourney::TEAMMATE_EMAIL, 'composer dev', 'php artisan saas:stripe');

    $organization = demoOrganization();
    $grace = User::query()->where('email', SeedDemoJourney::TEAMMATE_EMAIL)->firstOrFail();

    [$membership, $projects] = resolve(TenantContext::class)->runFor($organization, fn (): array => [
        Membership::query()->where('user_id', $grace->id)->firstOrFail(),
        Project::query()->orderBy('id')->pluck('name')->all(),
    ]);

    expect($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->role)->toBe(MembershipRank::Member)
        ->and($projects)->toBe(['Launch checklist', 'Pricing page'])
        ->and(User::query()->whereNull('email_verified_at')->count())->toBe(0);
});

it('seeds as many projects as the Free plan allows, whatever that is', function (): void {
    $freePrice = resolve(PlanCatalog::class)->findPlan('free')?->prices[0]->id;
    config(["billing.plans.free.prices.{$freePrice}.allowances.projects" => 3]);
    app()->forgetInstance(PlanCatalog::class);
    app()->forgetInstance(PriceCatalog::class);
    app()->forgetInstance(LocalResolver::class);

    $exitCode = Artisan::call('saas:demo');

    $projects = resolve(TenantContext::class)->runFor(demoOrganization(), fn (): int => Project::query()->count());

    expect($exitCode)->toBe(0)
        ->and($projects)->toBe(3)
        ->and(Artisan::output())->toContain('has used 3 of its 3 projects');
});

it('returns passwords that match the seeded accounts', function (): void {
    $seeded = resolve(SeedDemoJourney::class)->handle();

    expect(Hash::check($seeded['passwords']['owner'], $seeded['owner']->password))->toBeTrue()
        ->and(Hash::check($seeded['passwords']['teammate'], $seeded['teammate']->password))->toBeTrue()
        ->and($seeded['passwords']['owner'])->not->toBe($seeded['passwords']['teammate']);
});

it('records every seeded act as done by the console', function (): void {
    resolve(SeedDemoJourney::class)->handle();

    $events = resolve(TenantContext::class)->runFor(demoOrganization(), fn () => AuditEvent::query()->orderBy('id')->get());

    expect($events->pluck('action')->all())->toBe([AuditAction::InvitationSent, AuditAction::InvitationAccepted])
        ->and($events->pluck('source')->unique()->all())->toBe([AuditSource::Console])
        ->and($events->pluck('actor_id')->filter()->all())->toBeEmpty();
});

it('writes nothing when a step fails midway', function (): void {
    User::created(function (User $user): void {
        throw_if($user->email === SeedDemoJourney::TEAMMATE_EMAIL, RuntimeException::class, 'Registration failed.');
    });

    expect(fn (): array => resolve(SeedDemoJourney::class)->handle())->toThrow(RuntimeException::class, 'Registration failed.')
        ->and(User::query()->count())->toBe(0)
        ->and(Organization::query()->count())->toBe(0);
});

it('refuses a second run and points at --fresh', function (): void {
    $this->artisan('saas:demo')->assertSuccessful();

    $this->artisan('saas:demo')
        ->expectsOutputToContain('saas:demo --fresh')
        ->assertFailed();

    expect(User::query()->count())->toBe(2);
});
