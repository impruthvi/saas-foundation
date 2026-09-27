<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;

it('fails loudly rather than lazy loading', function (): void {
    expect(Model::preventsLazyLoading())->toBeTrue();
});

it('refuses to silently discard attributes', function (): void {
    expect(Model::preventsSilentlyDiscardingAttributes())->toBeTrue();
});

it('refuses to read attributes that were never retrieved', function (): void {
    expect(Model::preventsAccessingMissingAttributes())->toBeTrue();
});

it('does not autoload relationships under test', function (): void {
    // Autoloading resolves relations before preventLazyLoading is consulted, which
    // would stop the lazy-loading guard firing for models from a collection.
    expect(Model::isAutomaticallyEagerLoadingRelationships())->toBeFalse();
});
