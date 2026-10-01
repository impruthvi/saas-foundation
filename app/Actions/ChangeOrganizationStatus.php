<?php

declare(strict_types=1);

namespace App\Actions;

use App\Audit\AuditActor;
use App\Billing\BillingFacts;
use App\Contracts\Operators;
use App\Enums\AuditAction;
use App\Enums\OrganizationStatus;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class ChangeOrganizationStatus
{
    public function __construct(
        private Operators $operators,
        private BillingFacts $billing,
        private TenantContext $tenant,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Organization $organization, User $operator, OrganizationStatus $target, string $reason): Organization
    {
        throw_unless($this->operators->isOperator($operator), AuthorizationException::class);
        throw_if(mb_trim($reason) === '', DomainException::class, 'A reason is required to change an organization status.');

        return DB::transaction(function () use ($organization, $operator, $target, $reason): Organization {
            $locked = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->status;

            throw_if($previous === $target, DomainException::class, "This organization is already {$target->value}.");
            throw_if($previous === OrganizationStatus::Archived && $target === OrganizationStatus::Suspended, DomainException::class, 'Restore an archived organization before suspending it.');

            if ($target !== OrganizationStatus::Active) {
                $open = $this->tenant->runFor($locked, fn (): bool => $this->billing->hasOpenSubscription($locked));

                throw_if($open, DomainException::class, 'End the organization subscription before suspending or archiving it.');
            }

            $locked->forceFill(['status' => $target])->save();

            AuditActor::runAs(AuditActor::user($operator), fn (): AuditEvent => $this->audit->handle(
                $locked->id,
                AuditAction::OrganizationStatusChanged,
                $locked,
                [
                    'from' => $previous->value,
                    'to' => $target->value,
                    'reason' => mb_trim($reason),
                ],
            ));

            return $locked;
        });
    }
}
