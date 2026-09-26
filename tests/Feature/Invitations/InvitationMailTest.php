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

/**
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

        // A long-lived worker already holding another organization: without hydration
        // replacing it, restoring the invitation raises CrossTenantAccess.
        resolve(TenantContext::class)->set($other);

        // SerializesModels restores through newQueryWithoutScopes(), so the restoring
        // SELECT carries no organization_id. The row is addressed by primary key from
        // our own payload, and the retrieved guard checks the tenant; the next test
        // proves it still bites.
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

        // Standing propagation down leaves the worker on the wrong organization, the
        // condition the retrieved guard exists for.
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

        // Queued before the mail job runs, with no tenant; queued afterwards it would
        // capture that job's tenant and prove nothing.
        resolve(TenantContext::class)->forget();

        dispatch(function (): void {});

        TenantQueryGuard::allowUnscoped(function (): void {
            $this->artisan('queue:work --once')->assertSuccessful();
        });

        // Context is hydrated for every job, including ones carrying none, so this one
        // has to forget.
        $this->artisan('queue:work --once')->assertSuccessful();

        expect(resolve(TenantContext::class)->hasTenant())->toBeFalse();
    });
});
