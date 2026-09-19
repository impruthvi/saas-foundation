<?php

declare(strict_types=1);

use App\Actions\DeleteUser;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

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
