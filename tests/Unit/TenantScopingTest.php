<?php

declare(strict_types=1);

use App\Concerns\BelongsToOrganization;
use App\Contracts\TenantOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * @return list<class-string<Model>>
 */
function applicationModels(): array
{
    $models = [];

    foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
        $class = 'App\\Models\\'.Str::of($file->getRelativePathname())
            ->replace(['/', '.php'], ['\\', ''])
            ->value();

        if (is_subclass_of($class, Model::class)) {
            $models[] = $class;
        }
    }

    return $models;
}

it('gives every tenant-owned model the behaviour that enforces the claim', function (): void {
    $offenders = [];

    foreach (applicationModels() as $model) {
        if (! is_subclass_of($model, TenantOwned::class)) {
            continue;
        }

        if (! in_array(BelongsToOrganization::class, class_uses_recursive($model), true)) {
            $offenders[] = $model;
        }
    }

    expect($offenders)->toBe([], 'Implements TenantOwned without the trait that enforces it: '.implode(', ', $offenders));
});

it('makes every model carrying an organization_id declare itself tenant-owned', function (): void {
    $offenders = [];

    foreach (applicationModels() as $model) {
        /** @var Model $instance */
        $instance = new $model();

        if (! Schema::hasColumn($instance->getTable(), 'organization_id')) {
            continue;
        }

        if (! is_subclass_of($model, TenantOwned::class)) {
            $offenders[] = $model;
        }
    }

    expect($offenders)->toBe([], 'Carries an organization_id column without declaring TenantOwned: '.implode(', ', $offenders));
});

it('keeps the way around the scope to the places that are allowed it', function (): void {
    $allowed = [
        // Membership discovery runs before an organization is resolved.
        'app/Tenancy/MembershipRepository.php',
        // Token lookup runs before the invitation's organization is known.
        'app/Tenancy/InvitationRepository.php',
        'app/Tenancy/TenantContext.php',
        'app/Exceptions/TenantContextMissing.php',
        'app/Tenancy/TenantScope.php',
        'app/Concerns/BelongsToOrganization.php',
    ];

    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $path = 'app/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());

        if (in_array($path, $allowed, true)) {
            continue;
        }

        $contents = (string) file_get_contents($file->getRealPath());

        if (str_contains($contents, 'withoutTenantScope') || str_contains($contents, 'runWithoutTenant')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Escaping the tenant scope is an audited act: '.implode(', ', $offenders));
});
