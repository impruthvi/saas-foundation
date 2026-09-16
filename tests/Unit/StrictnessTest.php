<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Model strictness
|--------------------------------------------------------------------------
|
| The foundation's tenant scoping is enforced at the model boundary, so the
| strictness that makes a boundary violation loud is a load-bearing setting
| rather than a style preference. These assertions exist so that turning any
| of it off is a deliberate act with a failing test attached, not a quiet
| edit to a published config file.
|
*/

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
    // Autoloading resolves relations before preventLazyLoading is consulted
    // (Model::getRelationValue), which would leave the N+1 guard unable to
    // fire for models drawn from a collection. See tests/Pest.php.
    expect(Model::isAutomaticallyEagerLoadingRelationships())->toBeFalse();
});
