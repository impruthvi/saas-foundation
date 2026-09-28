<?php

declare(strict_types=1);

use App\Actions\StartImpersonation;
use App\Contracts\Operators;
use App\Enums\ImpersonationEnd;
use App\Filament\Middleware\ForgetTenantContext;
use App\Filament\Support\OperatorTable;
use App\Models\Impersonation;
use App\Models\Operator;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

function operator(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    Operator::factory()->create(['user_id' => $user->id]);

    return $user;
}

it('sends a guest to the product login', function (): void {
    $this->get('/admin')->assertRedirect(route('login'));
});

it('lets a verified operator in', function (): void {
    $this->actingAs(operator())->get('/admin')->assertOk();
});

it('refuses with 403', function (Closure $who): void {
    $this->actingAs($who())->get('/admin')->assertForbidden();
})->with([
    'someone who is not an operator' => [fn (): User => User::factory()->create()],
    'an operator with an unverified address' => [fn (): User => operator(['email_verified_at' => null])],
]);

it('requires two-factor authentication outside local development', function (): void {
    $withoutTwoFactor = operator();
    $withTwoFactor = operator(['two_factor_confirmed_at' => now()]);
    $environment = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $panel = Filament::getPanel('admin');

        expect($withoutTwoFactor->canAccessPanel($panel))->toBeFalse()
            ->and($withTwoFactor->canAccessPanel($panel))->toBeTrue();
    } finally {
        app()->detectEnvironment(fn (): string => $environment);
    }
});

it('refuses the console while acting as another user', function (): void {
    $operator = operator();
    $alice = User::factory()->create();
    $this->actingAs($operator);
    resolve(StartImpersonation::class)->handle($operator, $alice, 'Ticket 17', session()->driver());

    $this->get('/admin')->assertForbidden();
});

it('answers who is an operator from the console table', function (): void {
    $operator = operator();

    expect(resolve(Operators::class))->toBeInstanceOf(OperatorTable::class)
        ->and(resolve(Operators::class)->isOperator($operator))->toBeTrue()
        ->and(resolve(Operators::class)->isOperator(User::factory()->create()))->toBeFalse()
        ->and(resolve(Operators::class)->returnUrl())->toBe(url('/admin'));
});

it('checks the operator and forgets the organization on Livewire updates too', function (): void {
    expect(Livewire::getPersistentMiddleware())
        ->toContain(Authenticate::class)
        ->toContain(ForgetTenantContext::class);
});

it('grants console access from the command line with a reason', function (): void {
    $user = User::factory()->create(['email' => 'carol@example.com']);

    $this->artisan('operators:grant', ['email' => 'carol@example.com', '--reason' => 'Support rota'])
        ->assertSuccessful();

    expect(Operator::query()->where('user_id', $user->id)->sole()->reason)->toBe('Support rota');
});

it('refuses a grant', function (array $arguments, ?Closure $arrange): void {
    User::factory()->create(['email' => 'carol@example.com']);
    $arrange?->__invoke();

    $this->artisan('operators:grant', $arguments)->assertFailed();

    expect(Operator::query()->count())->toBe(! $arrange instanceof Closure ? 0 : 1);
})->with([
    'for an unknown address' => [['email' => 'nobody@example.com', '--reason' => 'Support rota'], null],
    'without a reason' => [['email' => 'carol@example.com'], null],
    'twice' => [['email' => 'carol@example.com', '--reason' => 'Again'], fn () => Artisan::call('operators:grant', ['email' => 'carol@example.com', '--reason' => 'Support rota'])],
]);

it('revokes console access and closes what the operator is doing as another user', function (): void {
    $operator = operator(['email' => 'carol@example.com']);
    $live = Impersonation::factory()->create(['operator_id' => $operator->id]);
    $finished = Impersonation::factory()->create(['operator_id' => $operator->id, 'ended_at' => now()->subDay(), 'ended_by' => ImpersonationEnd::Operator]);

    $this->artisan('operators:revoke', ['email' => 'carol@example.com'])->assertSuccessful();

    expect(Operator::query()->count())->toBe(0)
        ->and($live->fresh()?->ended_by)->toBe(ImpersonationEnd::Revoked)
        ->and($finished->fresh()?->ended_by)->toBe(ImpersonationEnd::Operator);
});

it('leaves an impersonation that had already run out to expiry, not revocation', function (): void {
    $operator = operator(['email' => 'carol@example.com']);
    $expired = Impersonation::factory()->create(['operator_id' => $operator->id, 'started_at' => now()->subHours(2), 'expires_at' => now()->subHour()]);

    $this->artisan('operators:revoke', ['email' => 'carol@example.com'])->assertSuccessful();

    expect($expired->fresh()?->ended_by)->toBeNull();
});

it('refuses to revoke someone who is not an operator', function (): void {
    User::factory()->create(['email' => 'carol@example.com']);

    $this->artisan('operators:revoke', ['email' => 'carol@example.com'])->assertFailed();
});
