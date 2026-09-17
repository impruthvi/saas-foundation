<?php

declare(strict_types=1);

use App\Actions\InviteOrganizationMember;
use App\Enums\MembershipRole;
use App\Exceptions\CrossTenantAccess;
use App\Mail\OrganizationInvitation;
use App\Models\Invitation;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TenantQueryGuard;

/*
|--------------------------------------------------------------------------
| The invitation email, and the tenant riding along with it
|--------------------------------------------------------------------------
|
| Two separate concerns, tested separately: what the message says, and whether
| the job that sends it survives a real queue.
|
| The propagation tests do not use Queue::fake() or the sync driver. phpunit.xml
| pins QUEUE_CONNECTION=sync, and sync runs the mailable inline with the sending
| organization still ambiently resolved — every assertion here would pass with
| M1's propagation deleted. So the payload goes to the jobs table, the tenant is
| changed to somebody else's, and the job is worked out of the database. If
| hydration does not overwrite the resolved tenant, `SerializesModels` restores
| this row under the wrong organization and the retrieved guard raises (D24).
|
*/

/**
 * Issue an invitation and queue its email, the way a request would.
 *
 * @return array{invitation: Invitation, token: string}
 */
function inviteAndMail(Organization $organization, string $email): array
{
    return resolve(TenantContext::class)->runFor($organization, function () use ($organization, $email): array {
        $issued = resolve(InviteOrganizationMember::class)
            ->handle($organization, $email, MembershipRole::Member);

        Mail::to($email)->queue(new OrganizationInvitation(
            $issued['invitation'],
            $organization->name,
            'https://example.test/invitations/'.$issued['token'],
        ));

        return $issued;
    });
}

it('names the organization and carries the accept link', function (): void {
    $organization = Organization::factory()->create(['name' => 'Acme']);
    $invitation = Invitation::factory()->make([
        'organization_id' => $organization->id,
        'expires_at' => now()->addDays(7),
    ]);

    $mailable = new OrganizationInvitation($invitation, 'Acme', 'https://example.test/invitations/abc123');

    $mailable->assertHasSubject('You have been invited to join Acme');
    $mailable->assertSeeInHtml('https://example.test/invitations/abc123', escape: false);
    $mailable->assertSeeInText('Acme');
    $mailable->assertSeeInText($invitation->expires_at->toFormattedDateString());
});

it('queues the invitation email rather than sending it inline', function (): void {
    Mail::fake();

    $organization = Organization::factory()->create();

    inviteAndMail($organization, 'invited@example.com');

    Mail::assertQueued(
        OrganizationInvitation::class,
        fn (OrganizationInvitation $mail): bool => $mail->hasTo('invited@example.com'),
    );
});

describe('across a real queue roundtrip', function (): void {
    beforeEach(function (): void {
        config()->set('queue.default', 'database');
    });

    it('writes the sending organization into the job payload', function (): void {
        $organization = Organization::factory()->create();

        inviteAndMail($organization, 'payload@example.com');

        $payload = (string) DB::table('jobs')->value('payload');

        expect($payload)->toContain('illuminate:log:context')
            ->and($payload)->toContain(TenantContext::KEY);
    });

    it('restores the sending organization on a worker holding a different one', function (): void {
        $sender = Organization::factory()->create(['name' => 'Sender']);
        $other = Organization::factory()->create(['name' => 'Someone Else']);

        inviteAndMail($sender, 'crosstenant@example.com');

        // The worst case M1 exists to prevent: a long-lived worker that already
        // has another organization resolved. If hydration does not replace it,
        // restoring the invitation raises CrossTenantAccess and the job fails.
        resolve(TenantContext::class)->set($other);

        // `SerializesModels` restores through `newQueryForRestoration()`, which
        // calls `newQueryWithoutScopes()` — so the restoring SELECT carries no
        // organization_id and the suite-wide guard fails the job on sight. That
        // is the framework behaviour D24 names, not a leak: the row is addressed
        // by primary key from a payload the application wrote, and the
        // `retrieved` guard is what checks it landed in the right tenant. The
        // test below proves that guard still bites.
        TenantQueryGuard::allowUnscoped(function (): void {
            $this->artisan('queue:work --once')->assertSuccessful();
        });

        expect(DB::table('failed_jobs')->count())->toBe(0)
            ->and(Mail::getSymfonyTransport()->messages())->toHaveCount(1);
    });

    it('refuses to restore the invitation when the resolved tenant is wrong', function (): void {
        $sender = Organization::factory()->create();
        $other = Organization::factory()->create();

        $issued = inviteAndMail($sender, 'guarded@example.com');

        // Propagation is what normally prevents this. Standing it down leaves
        // the worker holding the wrong organization, which is the exact
        // condition the retrieved guard exists for (D24). Without it, an
        // unscoped restoration would hand one tenant another tenant's row.
        resolve(TenantContext::class)->set($other);

        expect(fn (): mixed => TenantQueryGuard::allowUnscoped(
            fn (): ?Invitation => Invitation::query()
                ->withoutGlobalScopes()
                ->find($issued['invitation']->id),
        ))->toThrow(CrossTenantAccess::class);
    });

    it('leaves no tenant resolved for whatever the worker does next', function (): void {
        $organization = Organization::factory()->create();

        inviteAndMail($organization, 'after@example.com');

        // Queued with no tenant resolved, and queued BEFORE the mail job runs:
        // dispatching afterwards would capture the tenant that job resolved and
        // the test would prove nothing.
        resolve(TenantContext::class)->forget();

        dispatch(function (): void {
            //
        });

        TenantQueryGuard::allowUnscoped(function (): void {
            $this->artisan('queue:work --once')->assertSuccessful();
        });

        // The mail job resolved a tenant to do its work. Context is hydrated for
        // every job, including ones carrying none, so this one has to forget.
        $this->artisan('queue:work --once')->assertSuccessful();

        expect(resolve(TenantContext::class)->hasTenant())->toBeFalse();
    });
});
