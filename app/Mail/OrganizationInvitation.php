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
 * Carries an invitation's only usable token copy. The accept URL is injected so
 * the mailable remains independent of routing and can render in isolation.
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
