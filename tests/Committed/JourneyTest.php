<?php

declare(strict_types=1);

use App\Mail\OrganizationInvitation;
use App\Models\Organization;
use App\Models\User;
use App\Providers\Filament\AdminConsoleServiceProvider;
use Illuminate\Support\Facades\Mail;
use Stripe\StripeClient;
use Tests\Support\FakeStripeApi;
use Tests\Support\FakeStripeClient;
use Tests\Support\StripeWebhook;

/**
 * The ten-minute journey from the README, end to end. In the committed lane because the
 * plan change is applied by a refresh, which refuses to run inside a transaction.
 */
beforeEach(function (): void {
    config(['cashier.webhook.secret' => StripeWebhook::SECRET]);

    $this->provider = FakeStripeApi::install();
    app()->bind(StripeClient::class, fn (): StripeClient => new FakeStripeClient());
});

afterEach(function (): void {
    FakeStripeApi::uninstall();
});

it('walks the journey from registration to the admin console', function (): void {
    Mail::fake();

    visit('/register')
        ->type('name', 'Ada Lovelace')
        ->type('email', 'ada@example.com')
        ->type('password', 'correct-horse-battery')
        ->type('password_confirmation', 'correct-horse-battery')
        ->press('Create account')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();

    $ada = User::query()->where('email', 'ada@example.com')->firstOrFail();
    $organization = Organization::query()->where('owner_id', $ada->id)->where('personal', true)->firstOrFail();

    visit('/organizations/members')
        ->type('email', 'grace@example.com')
        ->press('Send invitation')
        ->assertSee('Pending invitations')
        ->assertSee('grace@example.com');

    Mail::assertQueued(OrganizationInvitation::class, fn (OrganizationInvitation $mail): bool => $mail->hasTo('grace@example.com'));

    visit('/projects')
        ->type('name', 'Launch checklist')
        ->press('Create project')
        ->assertSee('1 of 2 projects used')
        ->type('name', 'Pricing page')
        ->press('Create project')
        ->assertSee('2 of 2 projects used')
        ->assertSee('You have used every project on this plan')
        ->click('Upgrade to Pro')
        ->assertPathIs('/organizations/billing')
        ->press('Subscribe to Pro')
        ->assertPathIsNot('/organizations/billing');

    $customerId = (string) $organization->refresh()->stripe_id;
    StripeWebhook::reportSubscription($this->provider, $customerId, 'sub_journey', 'si_journey');

    visit('/projects')
        ->assertSee('2 of 10 projects used')
        ->assertDontSee('Your plan change is being applied')
        ->assertDontSee('You have used every project on this plan')
        ->type('name', 'Customer interviews')
        ->press('Create project')
        ->assertSee('Customer interviews')
        ->assertSee('3 of 10 projects used')
        ->assertNoJavaScriptErrors();

    if (! class_exists(AdminConsoleServiceProvider::class)) {
        return;
    }

    $operator = User::factory()->create(['email' => 'carol@example.com']);
    $this->artisan('operators:grant', ['email' => $operator->email, '--reason' => 'Journey'])->assertSuccessful();
    $this->actingAs($operator);

    visit("/admin/organizations/{$organization->getRouteKey()}")
        ->assertSee('Entitlements')
        ->assertSee('Pro')
        ->assertSee('10')
        ->assertSee('Usage')
        ->assertSee('evt_subscription_created')
        ->assertSee('customer.subscription.created')
        ->assertNoJavaScriptErrors();
});
