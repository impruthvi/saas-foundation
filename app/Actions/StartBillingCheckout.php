<?php

declare(strict_types=1);

namespace App\Actions;

use App\Billing\BillingFacts;
use App\Billing\Price;
use App\Enums\AuditAction;
use App\Exceptions\Billing\AlreadySubscribed;
use App\Exceptions\Billing\OrganizationNotBillable;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Checkout;
use LogicException;
use Stripe\Checkout\Session;
use Stripe\Customer;

final readonly class StartBillingCheckout
{
    public function __construct(
        private BillingFacts $facts,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(
        Organization $organization,
        Price $price,
        string $successUrl,
        string $cancelUrl,
    ): Checkout {
        throw_unless(
            $organization->status->isUsable(),
            OrganizationNotBillable::for($organization),
        );
        throw_if(
            $this->facts->hasOpenSubscription($organization),
            AlreadySubscribed::for($organization),
        );

        $customerId = $this->stripeCustomerId($organization);
        $session = Cashier::stripe()->checkout->sessions->create([
            'customer' => $customerId,
            'line_items' => [[
                'price' => $price->id,
                'quantity' => 1,
            ]],
            'mode' => Session::MODE_SUBSCRIPTION,
            'subscription_data' => [
                'metadata' => [
                    'is_on_session_checkout' => 'true',
                    'name' => 'default',
                    'type' => 'default',
                ],
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ], [
            'idempotency_key' => $this->checkoutIdempotencyKey($organization, $price),
        ]);

        $this->audit->handle($organization->id, AuditAction::CheckoutStarted, $organization, [
            'price_id' => $price->id,
            'checkout_session_id' => $session->id,
        ]);

        return new Checkout($organization, $session);
    }

    /**
     * The Stripe call stays outside the transaction; its stable idempotency key makes a
     * retry return the same customer.
     */
    private function stripeCustomerId(Organization $organization): string
    {
        if ($organization->hasStripeId()) {
            return $organization->stripeId();
        }

        $customer = Cashier::stripe()->customers->create([
            'name' => $organization->name,
            'email' => $organization->stripeEmail(),
        ], [
            'idempotency_key' => $this->customerIdempotencyKey($organization),
        ]);

        throw_unless($customer instanceof Customer && is_string($customer->id), LogicException::class, 'Stripe returned an invalid customer.');

        $customerId = DB::transaction(function () use ($customer, $organization): string {
            $locked = Organization::query()
                ->whereKey($organization->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->hasStripeId()) {
                $locked->forceFill(['stripe_id' => $customer->id])->save();
            }

            $stripeId = $locked->stripeId();
            throw_unless(is_string($stripeId), LogicException::class, 'The organization has no Stripe customer.');

            return $stripeId;
        });

        $organization->forceFill(['stripe_id' => $customerId]);
        $organization->syncOriginalAttribute('stripe_id');

        return $customerId;
    }

    private function customerIdempotencyKey(Organization $organization): string
    {
        return "billing:customer:organization:{$this->organizationReference($organization)}";
    }

    private function checkoutIdempotencyKey(Organization $organization, Price $price): string
    {
        $priceKey = mb_substr(hash('sha256', $price->id), 0, 24);
        $hour = now()->utc()->format('YmdH');

        return "billing:checkout:organization:{$this->organizationReference($organization)}:price:{$priceKey}:hour:{$hour}";
    }

    /**
     * Stripe replays an idempotency key for 24 hours across the whole account, so the key
     * names this organization in this database rather than its id alone. Otherwise a reset
     * database, or another install on the same account, is handed this one's customer.
     */
    private function organizationReference(Organization $organization): string
    {
        return mb_substr(hash('sha256', implode('|', [
            config()->string('app.key'),
            $organization->getKey(),
            $organization->created_at?->getTimestamp(),
        ])), 0, 32);
    }
}
