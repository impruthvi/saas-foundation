<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\StartImpersonation;
use App\Contracts\Operators;
use App\Enums\AuditAction;
use App\Enums\ImpersonationEnd;
use App\Enums\MembershipRank;
use App\Exceptions\ImpersonationRefused;
use App\Http\Middleware\EnsureImpersonationIsLive;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\AuditEvent;
use App\Models\Impersonation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

final class OperatorsForTest implements Operators
{
    /** @var list<int> */
    public array $ids = [];

    public function isOperator(User $user): bool
    {
        return in_array($user->id, $this->ids, true);
    }

    public function returnUrl(): string
    {
        return 'http://localhost/console';
    }
}

beforeEach(function (): void {
    $this->operators = new OperatorsForTest();
    app()->instance(Operators::class, $this->operators);
});

/**
 * @return array{0: User, 1: Organization}
 */
function signedInOperator(OperatorsForTest $operators): array
{
    [$organization, $operator] = organizationOwnedBySomeone('Operations');
    $operators->ids[] = $operator->id;

    test()->actingAs($operator)->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);

    return [$operator, $organization];
}

function startImpersonating(User $operator, User $user, string $reason = 'Ticket 4312: cannot see projects'): Impersonation
{
    return resolve(StartImpersonation::class)->handle($operator, $user, $reason, session()->driver());
}

it('signs the operator in as the user and keeps nothing else from their session', function (): void {
    [$operator] = signedInOperator($this->operators);
    [, $alice] = organizationOwnedBySomeone('Alice');
    session()->put('auth.password_confirmed_at', now()->unix());
    session()->put('url.intended', '/settings/security');
    $this->travelTo('2026-09-25 10:00:00');

    $impersonation = startImpersonating($operator, $alice);

    expect(Auth::id())->toBe($alice->id)
        ->and(session()->has('auth.password_confirmed_at'))->toBeFalse()
        ->and(session()->has('url.intended'))->toBeFalse()
        ->and(session()->has(ResolveTenantContext::SESSION_KEY))->toBeFalse()
        ->and(session()->get(Impersonation::SESSION_KEY))->toBe($impersonation->id)
        ->and(session()->get(Impersonation::IMPERSONATOR_SESSION_KEY))->toBe($operator->id)
        ->and($impersonation->expires_at->toDateTimeString())->toBe('2026-09-25 10:30:00')
        ->and($impersonation->reason)->toBe('Ticket 4312: cannot see projects');

    $this->getJson(route('password.confirmation'))->assertJson(['confirmed' => false]);
});

it('refuses to start', function (Closure $arrange, string $message): void {
    [$operator] = signedInOperator($this->operators);
    [$target, $reason] = $arrange($operator, $this->operators);

    expect(fn (): Impersonation => startImpersonating($operator, $target, $reason))
        ->toThrow(ImpersonationRefused::class, $message)
        ->and(Auth::id())->toBe($operator->id)
        ->and(Impersonation::query()->count())->toBe(0);
})->with([
    'someone who is no longer an operator' => [function (User $operator, OperatorsForTest $operators): array {
        $operators->ids = [];

        return [User::factory()->create(), 'Support'];
    }, 'Only an operator can act as another user.'],
    'as themselves' => [fn (User $operator): array => [$operator, 'Support'], 'You cannot act as yourself.'],
    'as another operator' => [function (User $operator, OperatorsForTest $operators): array {
        $other = User::factory()->create();
        $operators->ids[] = $other->id;

        return [$other, 'Support'];
    }, 'Operators cannot act as other operators.'],
    'as an unverified user' => [fn (): array => [User::factory()->unverified()->create(), 'Support'], 'This user has not verified their email address yet.'],
    'without a reason' => [fn (): array => [User::factory()->create(), '   '], 'Say why you need to act as this user.'],
]);

it('lets the operator use the product as the user', function (): void {
    [$operator] = signedInOperator($this->operators);
    [$aliceOrganization, $alice] = organizationOwnedBySomeone('Alice');
    startImpersonating($operator, $alice);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.user.id', $alice->id)
            ->where('organization.id', $aliceOrganization->id)
            ->where('impersonation.user', $alice->name)
            ->where('impersonation.operator', $operator->name));
});

it('shares no impersonation when there is none', function (): void {
    signedInOperator($this->operators);

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('impersonation', null));
});

it('names only routes that exist in the refused list', function (): void {
    $missing = array_values(array_filter(
        EnsureImpersonationIsLive::REFUSED_ROUTES,
        fn (string $name): bool => ! Route::has($name),
    ));

    expect($missing)->toBeEmpty();
});

it('refuses what an operator may not do as the user with 403', function (string $method, string $route): void {
    [$operator] = signedInOperator($this->operators);
    [, $alice] = organizationOwnedBySomeone('Alice');
    startImpersonating($operator, $alice);

    $this->call($method, route($route))->assertForbidden();

    expect(Auth::id())->toBe($alice->id);
})->with([
    'change name or email' => ['PATCH', 'profile.update'],
    'close the account' => ['DELETE', 'profile.destroy'],
    'change the password' => ['PUT', 'user-password.update'],
    'turn off two-factor' => ['DELETE', 'two-factor.disable'],
    'start a checkout' => ['POST', 'organizations.billing.checkout.store'],
    'cancel the subscription' => ['DELETE', 'organizations.billing.subscription.destroy'],
]);

it('hands the operator back with their own organization when the time runs out', function (): void {
    [$operator, $operatorOrganization] = signedInOperator($this->operators);
    [, $alice] = organizationOwnedBySomeone('Alice');
    $impersonation = startImpersonating($operator, $alice);
    $this->travel(31)->minutes();

    $this->get(route('dashboard'))->assertRedirect('http://localhost/console');

    expect(Auth::id())->toBe($operator->id)
        ->and(session()->has(Impersonation::SESSION_KEY))->toBeFalse()
        ->and($impersonation->fresh()?->ended_by)->toBe(ImpersonationEnd::Expiry);

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('organization.id', $operatorOrganization->id)
            ->where('impersonation', null));
});

it('signs the browser out when the operator is revoked mid-session', function (): void {
    [$operator] = signedInOperator($this->operators);
    [, $alice] = organizationOwnedBySomeone('Alice');
    $impersonation = startImpersonating($operator, $alice);
    $this->operators->ids = [];

    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
    expect($impersonation->fresh()?->ended_by)->toBe(ImpersonationEnd::Revoked);
});

it('signs the browser out when the session names an impersonation that already ended', function (): void {
    [$operator] = signedInOperator($this->operators);
    [, $alice] = organizationOwnedBySomeone('Alice');
    $impersonation = startImpersonating($operator, $alice);
    $impersonation->forceFill(['ended_at' => now(), 'ended_by' => ImpersonationEnd::Operator])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
});

it('ends from the banner and returns the operator to the console', function (): void {
    [$operator] = signedInOperator($this->operators);
    [, $alice] = organizationOwnedBySomeone('Alice');
    $impersonation = startImpersonating($operator, $alice);

    $this->delete(route('impersonation.destroy'))->assertRedirect('http://localhost/console');

    expect(Auth::id())->toBe($operator->id)
        ->and(session()->has(Impersonation::SESSION_KEY))->toBeFalse()
        ->and(session()->has(Impersonation::IMPERSONATOR_SESSION_KEY))->toBeFalse()
        ->and($impersonation->fresh()?->ended_by)->toBe(ImpersonationEnd::Operator);
});

it('refuses the end endpoint with 403 when nothing is being impersonated', function (): void {
    signedInOperator($this->operators);

    $this->delete(route('impersonation.destroy'))->assertForbidden();
});

it('ends on logout without signing the user out of their own devices', function (): void {
    [$operator] = signedInOperator($this->operators);
    [, $alice] = organizationOwnedBySomeone('Alice');
    $alice->forceFill(['remember_token' => 'alice-remembers-her-laptop'])->save();
    $impersonation = startImpersonating($operator, $alice);

    $this->post(route('logout'))->assertRedirect(route('home'));

    $this->assertGuest();
    expect($impersonation->fresh()?->ended_by)->toBe(ImpersonationEnd::Logout)
        ->and($alice->fresh()?->remember_token)->toBe('alice-remembers-her-laptop');
});

it('credits an act taken while impersonating to the impersonation', function (): void {
    [$operator] = signedInOperator($this->operators);
    [$organization, $alice] = organizationOwnedBySomeone('Alice');
    $bob = User::factory()->create();
    $membership = resolve(AddOrganizationMember::class)->handle($organization, $bob, MembershipRank::Member);
    $impersonation = startImpersonating($operator, $alice);

    $this->delete(route('organizations.members.destroy', $membership))->assertRedirect();

    $event = resolve(TenantContext::class)->runFor($organization, fn (): AuditEvent => AuditEvent::query()->sole());
    expect($event->action)->toBe(AuditAction::MemberRemoved)
        ->and($event->actor_id)->toBe($alice->id)
        ->and($event->impersonation_id)->toBe($impersonation->id)
        ->and(resolve(TenantContext::class)->runFor($organization, fn (): bool => Membership::query()->whereKey($membership->id)->exists()))->toBeFalse();
});
