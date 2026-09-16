<?php

declare(strict_types=1);

use App\Exceptions\TenantContextMissing;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantOwnedFixture;
use Tests\Support\TenantQueryGuard;

/*
|--------------------------------------------------------------------------
| The tenant boundary, asserted before the models it protects
|--------------------------------------------------------------------------
|
| These run against a fixture table rather than Organization or Project, so
| that what is under test is the rule and not one model's compliance with it.
|
*/

beforeEach(function (): void {
    Schema::create('tenant_owned_fixtures', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('organization_id');
        $table->string('name');
    });

    TenantQueryGuard::register('tenant_owned_fixtures');

    TenantQueryGuard::allowUnscoped(function (): void {
        TenantOwnedFixture::query()->withoutGlobalScope(TenantScope::class)->insert([
            ['organization_id' => 1, 'name' => 'belongs to one'],
            ['organization_id' => 2, 'name' => 'belongs to two'],
        ]);
    });
});

it('refuses to query a tenant-owned model with no organization resolved', function (): void {
    TenantOwnedFixture::query()->get();
})->throws(TenantContextMissing::class);

it('constrains every query to the resolved organization', function (): void {
    $tenant = resolve(TenantContext::class);
    $tenant->setId(1);

    $rows = TenantOwnedFixture::query()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->name)->toBe('belongs to one');
});

it('does not leak rows through a count, an update or a delete', function (): void {
    resolve(TenantContext::class)->setId(2);

    expect(TenantOwnedFixture::query()->count())->toBe(1);

    TenantOwnedFixture::query()->update(['name' => 'renamed']);
    TenantOwnedFixture::query()->delete();

    $remaining = TenantQueryGuard::allowUnscoped(
        fn () => TenantOwnedFixture::query()->withoutGlobalScope(TenantScope::class)->get()
    );

    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()?->name)->toBe('belongs to one');
});

it('restores the previous tenant after runForId, including no tenant at all', function (): void {
    $tenant = resolve(TenantContext::class);

    $names = $tenant->runForId(1, function () use ($tenant): array {
        $outer = TenantOwnedFixture::query()->pluck('name')->all();

        $inner = $tenant->runForId(2, fn (): array => TenantOwnedFixture::query()->pluck('name')->all());

        return [...$outer, ...$inner];
    });

    expect($names)->toBe(['belongs to one', 'belongs to two'])
        ->and($tenant->hasTenant())->toBeFalse()
        ->and(Context::get(TenantContext::KEY))->toBeNull();
});

it('mirrors the resolved organization into the context that queue payloads carry', function (): void {
    $tenant = resolve(TenantContext::class);

    $tenant->setId(7);

    expect(Context::get(TenantContext::KEY))->toBe(7);

    $tenant->forget();
    expect(Context::get(TenantContext::KEY))->toBeNull();
});

it('lets deliberately cross-tenant work run without a tenant, then restores it', function (): void {
    $tenant = resolve(TenantContext::class);
    $tenant->setId(1);

    $all = $tenant->runWithoutTenant(fn (): mixed => TenantQueryGuard::allowUnscoped(
        fn () => TenantOwnedFixture::query()->withoutGlobalScope(TenantScope::class)->count()
    ));

    expect($all)->toBe(2)
        ->and($tenant->id())->toBe(1);
});
