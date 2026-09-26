<?php

declare(strict_types=1);

namespace App\Operations;

use App\Enums\InvitationStatus;
use App\Models\AuditEvent;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Tenancy\TenantContext;

/**
 * Who is in an organization, who has been asked, and what has been done.
 *
 * Three reads that share one organization and one page in the console.
 */
final readonly class OrganizationActivity
{
    public function __construct(private TenantContext $tenant) {}

    /**
     * @return list<array{id: int, user_id: int, name: string, email: string, rank: string, status: string, owner: bool}>
     */
    public function members(Organization $organization): array
    {
        return $this->tenant->runFor($organization, fn (): array => array_values(Membership::query()
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn (Membership $membership): array => $this->presentMember($membership, $organization))
            ->all()));
    }

    /**
     * Invitations still waiting for an answer, expired or not.
     *
     * @return list<array{id: int, email: string, rank: string, expires_at: string, expired: bool}>
     */
    public function pendingInvitations(Organization $organization): array
    {
        return $this->tenant->runFor($organization, fn (): array => array_values(Invitation::query()
            ->where('status', InvitationStatus::Pending)
            ->oldest('expires_at')
            ->get()
            ->map(fn (Invitation $invitation): array => $this->presentInvitation($invitation))
            ->all()));
    }

    /**
     * Newest first.
     *
     * @return array{rows: list<array{id: int, action: string, source: string, actor: string|null, impersonated_by: string|null, subject_type: string|null, subject_id: int|null, context: array<string, mixed>, occurred_at: string}>, total: int}
     */
    public function auditLog(Organization $organization, int $page = 1, int $perPage = 25): array
    {
        return $this->tenant->runFor($organization, function () use ($page, $perPage): array {
            $events = AuditEvent::query()
                ->with(['actor', 'impersonation.operator'])
                ->latest('occurred_at')
                ->orderByDesc('id')
                ->paginate($perPage, page: $page);

            return [
                'rows' => array_values(array_map($this->presentEvent(...), $events->items())),
                'total' => $events->total(),
            ];
        });
    }

    /**
     * @return array{id: int, user_id: int, name: string, email: string, rank: string, status: string, owner: bool}
     */
    private function presentMember(Membership $membership, Organization $organization): array
    {
        return [
            'id' => $membership->id,
            'user_id' => $membership->user_id,
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'rank' => $membership->role->value,
            'status' => $membership->status->value,
            'owner' => $membership->user_id === $organization->owner_id,
        ];
    }

    /**
     * @return array{id: int, email: string, rank: string, expires_at: string, expired: bool}
     */
    private function presentInvitation(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'rank' => $invitation->role->value,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'expired' => $invitation->hasExpired(),
        ];
    }

    /**
     * @return array{id: int, action: string, source: string, actor: string|null, impersonated_by: string|null, subject_type: string|null, subject_id: int|null, context: array<string, mixed>, occurred_at: string}
     */
    private function presentEvent(AuditEvent $event): array
    {
        return [
            'id' => $event->id,
            'action' => $event->action->value,
            'source' => $event->source->value,
            'actor' => $event->actor?->name,
            'impersonated_by' => $event->impersonation?->operator?->name,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            'context' => $event->context,
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ];
    }
}
