<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/
use App\Actions\CreateOrganization;
use App\Actions\InviteOrganizationMember;
use App\Enums\MembershipRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\InvitationRepository;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\AuthorizationTeamGuard;
use Tests\Support\TenantQueryGuard;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Automatic relationship autoloading runs *before* the lazy-loading check in
        // Model::getRelationValue(), and returns early when it succeeds. Left enabled
        // under test, it silently satisfies every relation accessed on a model that came
        // from a collection, so ShouldBeStrict's preventLazyLoading never fires for the
        // one case it exists to catch. Disabled here, and only here: the convenience is
        // real in development and production, but a guard that cannot fail is not a guard.
        // The framework makes the same call itself in Factory::createChildren().
        Model::automaticallyEagerLoadRelationships(false);

        // The tenant boundary is a property of queries, so it is asserted against
        // queries, for every test in the suite rather than for the handful written
        // with tenancy in mind. See tests/Support/TenantQueryGuard.php and D20.
        TenantQueryGuard::flush();

        // The role and permission assignments are tenant-owned in everything but
        // name: they carry an organization_id and are meaningless without one.
        // No model declares them, so they are registered by hand (D29).
        TenantQueryGuard::register('model_has_roles');
        TenantQueryGuard::register('model_has_permissions');

        TenantQueryGuard::install();

        // The authorization boundary needs its own guard, because it does not
        // leak through SQL the way the tenant boundary does: a stale permissions
        // team answers can() for the wrong organization while every query stays
        // correctly scoped. See tests/Support/AuthorizationTeamGuard.php and D29.
        AuthorizationTeamGuard::flush();
        AuthorizationTeamGuard::install();

        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Browser', 'Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/*
|--------------------------------------------------------------------------
| Invitations
|--------------------------------------------------------------------------
|
| Shared by the invitation tests. They live here rather than in one file
| because three files need them and Pest helpers are file-scoped.
|
*/

/**
 * Make a request or a read that resolves an invitation by its token.
 *
 * Those reads hit `invitations` with no organization_id, which is D27's audited
 * door and exactly what the suite-wide guard is built to flag. Standing the
 * guard down lives in this one named helper, so a test that needs it says so and
 * every other test keeps full protection.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $work
 * @return TReturn
 */
function throughTheAuditedDoor(Closure $work): mixed
{
    return TenantQueryGuard::allowUnscoped($work);
}

/**
 * Close an account, which revokes its roles in every organization at once.
 *
 * `HasRoles` detaches across all teams on delete, so the deletes it emits
 * carry no organization_id and the query guard flags them. That crossing is
 * intended — an account being closed should keep grants nowhere — so it gets a
 * door with a name rather than a blanket exemption, and the name says which
 * crossing is being allowed.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $work
 * @return TReturn
 */
function whileClosingAnAccount(Closure $work): mixed
{
    return TenantQueryGuard::allowUnscoped($work);
}

/**
 * Resolve an invitation the way a stranger's request does.
 */
function findInvitation(string $token): ?Invitation
{
    return throughTheAuditedDoor(
        fn (): ?Invitation => resolve(InvitationRepository::class)->findByToken($token),
    );
}

/**
 * Invite an address, and hand back the token the email would have carried.
 */
function issueInvitation(Organization $organization, string $email, ?User $by = null): string
{
    return resolve(TenantContext::class)->runFor(
        $organization,
        fn (): string => resolve(InviteOrganizationMember::class)
            ->handle($organization, $email, MembershipRole::Member, $by)['token'],
    );
}

/**
 * An organization and the user who owns it.
 *
 * @return array{0: Organization, 1: User}
 */
function organizationOwnedBySomeone(string $name = 'Acme'): array
{
    $owner = User::factory()->create();

    return [resolve(CreateOrganization::class)->handle($owner, $name), $owner];
}
