<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email that carries an invitation's only usable copy of its token.
 *
 * A Mailable rather than a Notification because the recipient usually has no
 * `User` row to notify — that is the whole reason invitations exist.
 *
 * This is also the first queued work in the product surface, which makes it the
 * first real consumer of M1's tenant propagation rather than a fixture:
 *
 *   queue()  ──► Context dehydrated into the payload   (tenant.organization_id)
 *                         │
 *                         ▼
 *   worker   ──► Context::hydrate ──► TenantContext restored
 *                         │
 *                         ▼
 *            SerializesModels restores $invitation with the global scope
 *            bypassed (newQueryForRestoration), so the `retrieved` guard is
 *            the only thing checking the row belongs where it should (D24).
 *
 * Get that wrong and a worker that had another organization resolved reads this
 * row anyway. The test for this deliberately resolves a *different* tenant
 * before working the queue, so the assertion can fail.
 *
 * The accept URL is passed in rather than built here. Routing is a delivery
 * concern belonging to whoever handled the request, and keeping it out means the
 * mailable can be rendered in a test without a route table.
 *
 * Note on the payload: a queued mailable carrying a link puts the token in the
 * jobs table until the job is worked. That is the same trade Laravel's own
 * password-reset notification makes, and it is why revocation exists.
 */
final class OrganizationInvitation extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Invitation $invitation,
        public string $organizationName,
        public string $acceptUrl,
    ) {
        // The invitation row is written inside a transaction, so without this a
        // worker can pick the job up before the row it needs is committed.
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You have been invited to join :organization', [
                'organization' => $this->organizationName,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.organizations.invitation',
        );
    }
}
