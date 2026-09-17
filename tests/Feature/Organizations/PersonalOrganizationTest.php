<?php

declare(strict_types=1);

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Tests\Support\TenantQueryGuard;

/*
|--------------------------------------------------------------------------
| Register -> personal organization auto-created
|--------------------------------------------------------------------------
|
| The first clause of the ten-minute journey (D8), and the reason D1 says the
| organization is always the tenant: a solo user is an organization of one
| rather than a second billing subject threaded through the product.
|
*/

it('gives a newly registered user exactly one personal organization', function (): void {
    $response = $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'ada@example.com')->sole();

    $organizations = Organization::query()->where('owner_id', $user->id)->get();

    expect($organizations)->toHaveCount(1);

    $organization = $organizations->sole();

    expect($organization->personal)->toBeTrue()
        ->and($organization->name)->toBe("Ada Lovelace's Workspace")
        ->and($organization->slug)->toBe('ada-lovelaces-workspace')
        ->and($organization->owner_id)->toBe($user->id);

    $membership = TenantQueryGuard::allowUnscoped(
        fn () => Membership::query()->withoutTenantScope()->where('user_id', $user->id)->sole()
    );

    expect($membership->organization_id)->toBe($organization->id)
        ->and($membership->role)->toBe(MembershipRole::Admin)
        ->and($membership->status)->toBe(MembershipStatus::Active);
});

it('leaves no user behind when the organization cannot be created', function (): void {
    // A name that slugs to nothing still has to produce an organization, because
    // a user without one is a state this application does not have.
    $this->post(route('register.store'), [
        'name' => '???',
        'email' => 'symbols@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'symbols@example.com')->sole();

    expect(Organization::query()->where('owner_id', $user->id)->count())->toBe(1);
});

it('settles a slug collision with the unique index rather than a lookup', function (): void {
    foreach (['first@example.com', 'second@example.com'] as $email) {
        $this->post(route('register.store'), [
            'name' => 'Same Name',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        auth()->logout();
    }

    $slugs = Organization::query()->orderBy('id')->pluck('slug')->all();

    expect($slugs)->toHaveCount(2)
        ->and($slugs[0])->toBe('same-names-workspace')
        ->and($slugs[1])->toStartWith('same-names-workspace-')
        ->and($slugs[0])->not->toBe($slugs[1]);
});
