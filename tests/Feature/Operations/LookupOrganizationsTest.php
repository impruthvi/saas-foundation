<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Models\User;
use App\Operations\LookupOrganizations;

it('finds an organization by any of the four things an operator might have', function (string $search): void {
    [$acme] = organizationOwnedBySomeone('Acme Rockets');
    $acme->forceFill(['stripe_id' => 'cus_acme'])->save();
    resolve(AddOrganizationMember::class)->handle($acme, User::factory()->create(['email' => 'bob@example.com']));
    organizationOwnedBySomeone('Globex');

    expect(resolve(LookupOrganizations::class)->matching($search))->toBe([$acme->id]);
})->with([
    'part of the name' => ['Acme Rock'],
    'part of the slug' => ['acme-rockets'],
    'the Stripe customer id' => ['cus_acme'],
    'a member address, however it was typed' => [' Bob@Example.com '],
]);

it('finds nothing for a Stripe customer id it only partly matches', function (): void {
    [$acme] = organizationOwnedBySomeone('Acme Rockets');
    $acme->forceFill(['stripe_id' => 'cus_acme'])->save();

    expect(resolve(LookupOrganizations::class)->matching('cus_ac'))->toBeEmpty();
});
