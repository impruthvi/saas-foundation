<?php

declare(strict_types=1);

use App\Actions\DeleteUser;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Closing an account revokes everywhere, and leaves the door shut behind it
|--------------------------------------------------------------------------
|
| `HasRoles` detaches across every team on delete, and does it by turning team
| scoping off, detaching, and turning it back on as its last statement —
| outside a `finally`. So a detach that raises leaves scoping off for the rest
| of the process. In a queue worker that is every later can() answered with the
| organization ignored, which is the widest failure this milestone can produce
| and one that no query would reveal.
|
| Reaching that state needs the detach to fail. The query guard supplies the
| failure honestly: run the deletion without the named door and it raises on
| the unscoped delete, from inside the hook, after scoping is already off.
|
*/

it('leaves team scoping on when closing an account fails part-way', function (): void {
    $user = User::factory()->create();
    $permissions = resolve(PermissionRegistrar::class);

    try {
        resolve(DeleteUser::class)->handle($user);
        $this->fail('The unscoped cross-team detach should have been refused by the query guard.');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toContain('model_has_roles');
    }

    expect($permissions->teams)->toBeTrue();
});

it('closes an account when the crossing is declared', function (): void {
    $user = User::factory()->create();

    whileClosingAnAccount(fn () => resolve(DeleteUser::class)->handle($user));

    expect(User::query()->find($user->id))->toBeNull()
        ->and(resolve(PermissionRegistrar::class)->teams)->toBeTrue();
});
