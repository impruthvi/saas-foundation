<?php

declare(strict_types=1);

use App\Actions\CreateProject;
use App\Models\Operator;
use App\Models\User;
use App\Providers\Filament\AdminConsoleServiceProvider;
use App\Tenancy\TenantContext;
use Stripe\StripeClient;
use Tests\Support\FakeStripeApi;
use Tests\Support\FakeStripeClient;
use Tests\Support\StripeWebhook;

/**
 * In the committed lane because the entitlement only moves after a refresh, and a
 * refresh refuses to run inside a transaction.
 */
beforeEach(function (): void {
    config(['cashier.webhook.secret' => StripeWebhook::SECRET]);

    $this->provider = FakeStripeApi::install();
    app()->bind(StripeClient::class, fn (): StripeClient => new FakeStripeClient());
});

afterEach(function (): void {
    FakeStripeApi::uninstall();
});

it('shows an operator the entitlement, the usage and the Stripe event that set it', function (): void {
    [$organization] = organizationOwnedBySomeone();
    $organization->forceFill(['stripe_id' => 'cus_journey'])->save();
    resolve(TenantContext::class)->runFor($organization, function () use ($organization): void {
        resolve(CreateProject::class)->handle($organization, 'Launch checklist', 'admin-journey-one');
        resolve(CreateProject::class)->handle($organization, 'Pricing page revamp', 'admin-journey-two');
    });

    StripeWebhook::reportSubscription($this->provider, 'cus_journey', 'sub_journey', 'si_journey');

    $operator = User::factory()->create(['name' => 'Carol Operator']);
    Operator::factory()->create(['user_id' => $operator->id]);
    $this->actingAs($operator);

    visit("/admin/organizations/{$organization->getRouteKey()}")
        ->assertSee('Entitlements')
        ->assertSee('Pro')
        ->assertSee('package')
        ->assertSee('10')
        ->assertSee('Usage')
        ->assertSee('These Stripe events, received in the same second')
        ->assertSee('evt_subscription_created')
        ->assertSee('customer.subscription.created')
        ->assertNoJavaScriptErrors();
})->skip(! class_exists(AdminConsoleServiceProvider::class), 'The admin console is not installed.');
