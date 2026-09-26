<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Fortify\CreateNewUser;
use App\Audit\AuditActor;
use App\Enums\AuditSource;
use App\Enums\MembershipRole;
use App\Exceptions\DemoRefused;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the journey up to the Free plan's limit through the product's own actions, so
 * the demo writes exactly what a user would, audit events included.
 */
final readonly class SeedDemoJourney
{
    public const string OWNER_EMAIL = 'ada@example.com';

    public const string TEAMMATE_EMAIL = 'grace@example.com';

    public function __construct(
        private CreateNewUser $users,
        private InviteOrganizationMember $invitations,
        private AcceptOrganizationInvitation $acceptances,
        private CreateProject $projects,
        private TenantContext $tenant,
    ) {}

    /**
     * The seeded passwords are printed, so a reachable server must never run this.
     */
    public static function refuseOutsideLocal(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw DemoRefused::outsideLocal((string) app()->environment());
        }
    }

    /**
     * @return array{organization: Organization, owner: User, teammate: User, passwords: array{owner: string, teammate: string}}
     */
    public function handle(): array
    {
        self::refuseOutsideLocal();

        throw_if(User::query()->where('email', self::OWNER_EMAIL)->exists(), DemoRefused::alreadySeeded());

        $passwords = [
            'owner' => Str::password(20, symbols: false),
            'teammate' => Str::password(20, symbols: false),
        ];

        return AuditActor::runAs(
            AuditActor::source(AuditSource::Console),
            fn (): array => DB::transaction(fn (): array => $this->seed($passwords)),
        );
    }

    /**
     * @param  array{owner: string, teammate: string}  $passwords
     * @return array{organization: Organization, owner: User, teammate: User, passwords: array{owner: string, teammate: string}}
     */
    private function seed(array $passwords): array
    {
        $owner = $this->register('Ada Lovelace', self::OWNER_EMAIL, $passwords['owner']);
        $teammate = $this->register('Grace Hopper', self::TEAMMATE_EMAIL, $passwords['teammate']);

        $organization = Organization::query()
            ->where('owner_id', $owner->id)
            ->where('personal', true)
            ->firstOrFail();

        /** @var Invitation $invitation */
        $invitation = $this->tenant->runFor(
            $organization,
            fn (): Invitation => $this->invitations->handle($organization, self::TEAMMATE_EMAIL, MembershipRole::Member, $owner)['invitation'],
        );

        $this->acceptances->handle($invitation, $teammate);

        $this->tenant->runFor($organization, function () use ($organization): void {
            $this->projects->handle($organization, 'Launch checklist', 'saas-demo-1');
            $this->projects->handle($organization, 'Pricing page', 'saas-demo-2');
        });
        /* @chisel-admin-console */
        resolve(GrantOperator::class)->handle($owner, 'saas:demo');
        /* @end-chisel-admin-console */

        return [
            'organization' => $organization,
            'owner' => $owner,
            'teammate' => $teammate,
            'passwords' => $passwords,
        ];
    }

    /**
     * Marked verified because the admin console refuses an unverified address, and the
     * seeded addresses cannot receive mail.
     */
    private function register(string $name, string $email, string $password): User
    {
        $user = $this->users->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
