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
 * Apply a recorded Stripe event that was never applied, as if it had just been
 * delivered.
 *
 * It goes through the same placing and ordering as a live delivery. It skips
 * the package listener, which needs a live signed request, but not the listener's
 * other checks: the event must be one that affects entitlements, from the mode
 * and account this application serves, for exactly one organization. A replayed
 * `customer.deleted` therefore never asks for a refresh that would fail once the
 * customer is gone.
 *
 *   unplaceable / errored only
 *     ├─ livemode or account differ ──▶ refused, nothing applied
 *     ├─ still no organization ───────▶ unplaceable
 *     ├─ a newer event won ───────────▶ superseded
 *     ├─ Cashier's handler throws ────▶ errored
 *     └─ applied ─────────────────────▶ refresh requested if it affects entitlements
 *                                       ─▶ replayed
 */
final readonly class ReplayWebhookEvent
{
    /**
     * The event types the entitlement package refreshes on. Mirrors
     * `QueueRefreshFromWebhook`, and a test fails if the two drift apart.
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
    ) {}

    public function handle(WebhookEvent $event): WebhookOutcome
    {
        throw_unless(
            in_array($event->outcome, [WebhookOutcome::Unplaceable, WebhookOutcome::Errored], true),
            InvalidArgumentException::class,
            'Only an event that was never applied can be replayed.',
        );

        $payload = $event->payload;

        if (! $this->servesThisApplication($payload)) {
            return $this->finish($event, WebhookOutcome::Refused, 'ContextMismatch', 'The event is from another Stripe mode or account.');
        }

        try {
            $result = $this->apply->handle(
                $payload,
                fn (): Response => resolve(CashierEventHandlers::class)->apply($payload),
            );
        } catch (Throwable) {
            return $this->finish($event, WebhookOutcome::Errored);
        }

        if ($result instanceof WebhookOutcome) {
            return $this->finish($event, $result);
        }

        $organization = $this->organizationOf($event);

        if ($organization instanceof Organization && in_array($event->type, self::REFRESHING_EVENTS, true)) {
            try {
                $this->tenant->runFor($organization, fn (): OwnerReference => $this->refreshes->request($organization, $event->stripe_event_id));
            } catch (ReadFailure $readFailure) {
                return $this->finish($event, WebhookOutcome::Refused, 'RefreshRefused', $readFailure->getMessage());
            }
        }

        return $this->finish($event, WebhookOutcome::Replayed);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function servesThisApplication(array $payload): bool
    {
        return ($payload['livemode'] ?? null) === config('cashier-entitlements.live_mode')
            && ($payload['account'] ?? 'platform') === config('cashier-entitlements.provider_context');
    }

    /**
     * Settle the row on this replay's outcome and audit it where there is an
     * organization to audit it under.
     */
    private function finish(WebhookEvent $event, WebhookOutcome $outcome, ?string $reason = null, ?string $message = null): WebhookOutcome
    {
        $event->refresh();

        if ($outcome === WebhookOutcome::Replayed || $outcome === WebhookOutcome::Refused) {
            $event->forceFill(['outcome' => $outcome, 'outcome_reason' => $reason, 'outcome_message' => $message])->save();
        }

        $organization = $this->organizationOf($event);

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
