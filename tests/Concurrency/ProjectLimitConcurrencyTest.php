<?php

declare(strict_types=1);

use App\Actions\CreateProject;
use App\Models\Organization;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Usage\LimitExceeded;
use Tests\Support\Contenders;
use Tests\Support\Outcome;

/** Attempt one project creation from inside a forked process. */
function attemptProjectCreation(int $organizationId, string $name, string $token): Outcome
{
    $organization = Organization::query()->findOrFail($organizationId);

    try {
        resolve(TenantContext::class)->runFor(
            $organization,
            fn (): Project => resolve(CreateProject::class)->handle($organization, $name, $token),
        );

        return Outcome::Succeeded;
    } catch (LimitExceeded) {
        return Outcome::Refused;
    }
}

it('admits exactly one simultaneous project when one allowance remains', function (): void {
    [$organization] = organizationOwnedBySomeone();

    resolve(TenantContext::class)->runFor(
        $organization,
        fn (): Project => resolve(CreateProject::class)->handle($organization, 'Existing project', 'existing'),
    );

    $outcomes = Contenders::race([
        fn (): Outcome => attemptProjectCreation($organization->id, 'First contender', 'first-contender'),
        fn (): Outcome => attemptProjectCreation($organization->id, 'Second contender', 'second-contender'),
    ]);

    $outcomeNames = array_map(fn (Outcome $outcome): string => $outcome->name, $outcomes);
    sort($outcomeNames);

    $projectCount = resolve(TenantContext::class)->runFor(
        $organization,
        fn (): int => Project::query()->count(),
    );
    $owner = resolve(OwnerLocator::class)->reference($organization);

    expect($outcomeNames)->toBe([
        Outcome::Refused->name,
        Outcome::Succeeded->name,
    ])
        ->and($projectCount)->toBe(2)
        ->and(resolve(LocalResolver::class)->usageStore()->usage(
            $owner,
            'projects',
            now()->toDateTimeImmutable(),
        ))->toBe(2);
});
