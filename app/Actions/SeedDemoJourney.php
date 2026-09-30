<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Fortify\CreateNewUser;
use App\Audit\AuditActor;
use App\Entitlements\ResolveAllowance;
use App\Enums\AuditSource;
use App\Enums\MembershipRank;
use App\Exceptions\DemoRefused;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use LogicException;

/**
 * Seeds the journey up to the Free plan's limit through the product's own actions, so
 * the demo writes exactly what a user would, audit events included.
 */
final readonly class SeedDemoJourney
{
    public const string OWNER_EMAIL = 'ada@example.com';

    public const string TEAMMATE_EMAIL = 'grace@example.com';

    private const array PROJECT_NAMES = ['Launch checklist', 'Pricing page'];

    public function __construct(
        private CreateNewUser $users,
        private InviteOrganizationMember $invitations,
        private AcceptOrganizationInvitation $acceptances,
        private CreateProject $projects,
        private TenantContext $tenant,
        private OwnerLocator $owners,
        private ResolveAllowance $allowances,
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
     * @return array{organization: Organization, owner: User, teammate: User, passwords: array{owner: string, teammate: string}, projects: int}
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
     * @return array{organization: Organization, owner: User, teammate: User, passwords: array{owner: string, teammate: string}, projects: int}
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
            fn (): Invitation => $this->invitations->handle($organization, self::TEAMMATE_EMAIL, MembershipRank::Member, $owner)['invitation'],
        );

        $this->acceptances->handle($invitation, $teammate);

        $projects = $this->tenant->runFor($organization, function () use ($organization): int {
            $limit = $this->allowances->limit($this->owners->reference($organization), Project::FEATURE, Date::now()->toDateTimeImmutable())
                ?? throw new LogicException('The demo seeds up to the Free plan limit, so the Free plan must limit projects.');

            for ($number = 1; $number <= $limit; $number++) {
                $this->projects->handle($organization, self::PROJECT_NAMES[$number - 1] ?? "Project {$number}", "saas-demo-{$number}");
            }

            return $limit;
        });
        /* @chisel-admin-console */
        resolve(GrantOperator::class)->handle($owner, 'saas:demo');
        /* @end-chisel-admin-console */

        return [
            'organization' => $organization,
            'owner' => $owner,
            'teammate' => $teammate,
            'passwords' => $passwords,
            'projects' => $projects,
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
