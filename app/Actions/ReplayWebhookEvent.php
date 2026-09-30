<?php

declare(strict_types=1);

namespace App\Actions;

use App\Billing\ApplyStripeEvent;
use App\Billing\CashierEventHandlers;
use App\Enums\AuditAction;
use App\Enums\WebhookOutcome;
use App\Models\Organization;
use App\Models\WebhookEvent;
use App\Tenancy\TenantContext;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Reconciliation\RefreshManager;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Skips the package listener, which needs a live signed request, but repeats its
 * checks: an entitlement-affecting type, this application's mode and account, and
 * exactly one organization.
 */
final readonly class ReplayWebhookEvent
{
    /**
     * Mirrors QueueRefreshFromWebhook; a test fails if they drift.
     *
     * @var list<string>
     */
    public const array REFRESHING_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.payment_succeeded',
        'invoice.payment_failed',
    ];

    public function __construct(
        private ApplyStripeEvent $apply,
        private RefreshManager $refreshes,
        private RecordAuditEvent $audit,
        private TenantContext $tenant,
        private CashierEventHandlers $handlers,
    ) {}

    public function handle(WebhookEvent $event): WebhookOutcome
    {
        throw_unless(
            $event->outcome->isReplayable(),
            InvalidArgumentException::class,
            'Only an event that was never applied can be replayed.',
        );

        $payload = $event->payload;

        // Found before applying: a customer deletion unlinks the organization it names.
        $organization = $this->organizationOf($event);

        if (! $this->servesThisApplication($payload)) {
            return $this->finish($event, $organization, WebhookOutcome::Refused, 'ContextMismatch', 'The event is from another Stripe mode or account.');
        }

        try {
            $result = $this->apply->handle(
                $payload,
                fn (): Response => $this->handlers->apply($payload),
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return $this->finish($event, $organization, WebhookOutcome::Errored);
        }

        if ($result instanceof WebhookOutcome) {
            return $this->finish($event, $organization, $result);
        }

        if ($organization instanceof Organization && in_array($event->type, self::REFRESHING_EVENTS, true)) {
            try {
                $this->tenant->runFor($organization, fn (): OwnerReference => $this->refreshes->request($organization, $event->stripe_event_id));
            } catch (ReadFailure $readFailure) {
                return $this->finish($event, $organization, WebhookOutcome::Refused, 'RefreshRefused', $readFailure->getMessage());
            }
        }

        return $this->finish($event, $organization, WebhookOutcome::Replayed);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function servesThisApplication(array $payload): bool
    {
        return ($payload['livemode'] ?? null) === config('cashier-entitlements.live_mode')
            && ($payload['account'] ?? 'platform') === config('cashier-entitlements.provider_context');
    }

    private function finish(WebhookEvent $event, ?Organization $organization, WebhookOutcome $outcome, ?string $reason = null, ?string $message = null): WebhookOutcome
    {
        $event->refresh();

        if ($outcome === WebhookOutcome::Replayed || $outcome === WebhookOutcome::Refused) {
            $event->forceFill(['outcome' => $outcome, 'outcome_reason' => $reason, 'outcome_message' => $message])->save();
        }

        if ($organization instanceof Organization) {
            $this->audit->handle($organization->id, AuditAction::WebhookReplayed, $event, [
                'stripe_event_id' => $event->stripe_event_id,
                'type' => $event->type,
                'outcome' => $outcome->value,
            ]);
        }

        return $outcome;
    }

    private function organizationOf(WebhookEvent $event): ?Organization
    {
        if ($event->stripe_customer_id === null) {
            return null;
        }

        return Organization::query()->where('stripe_id', $event->stripe_customer_id)->first();
    }
}
