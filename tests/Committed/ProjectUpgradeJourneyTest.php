<?php

declare(strict_types=1);

use App\Actions\CreateProject;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Tenancy\TenantContext;
use Stripe\StripeClient;
use Tests\Support\FakeStripeApi;
use Tests\Support\FakeStripeClient;
use Tests\Support\StripeWebhook;

/**
 * In the committed lane because the refusal is lifted only by a refresh, which refuses
 * to run inside a transaction. Two fakes: Checkout goes through Cashier's client
 * (FakeStripeClient), while the refresh builds its own client and is reached only
 * through FakeStripeApi.
 */
beforeEach(function (): void {
    config(['cashier.webhook.secret' => StripeWebhook::SECRET]);

    $this->provider = FakeStripeApi::install();
    app()->bind(StripeClient::class, fn (): StripeClient => new FakeStripeClient());
});

afterEach(function (): void {
    FakeStripeApi::uninstall();
});

/** @return array{0: Organization, 1: User} */
function organizationAtTheFreeLimit(): array
{
    [$organization, $owner] = organizationOwnedBySomeone();
    $organization->forceFill(['stripe_id' => 'cus_journey'])->save();

    resolve(TenantContext::class)->runFor($organization, function () use ($organization): void {
        $create = resolve(CreateProject::class);

        $create->handle($organization, 'Launch checklist', 'journey-one');
        $create->handle($organization, 'Pricing page revamp', 'journey-two');
    });

    test()->actingAs($owner)->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);

    return [$organization, $owner];
}

it('refuses at the limit, goes through checkout, and then allows the same project', function (): void {
    [$organization] = organizationAtTheFreeLimit();

    $page = visit('/projects');

    $page->assertSee('2 of 2 projects used')
        ->assertSee('You have used every project on this plan')
        ->assertSee('Upgrade to Pro')
        ->assertNoJavaScriptErrors();

    // Hiding the button is not the limit: the endpoint refuses a request that arrives
    // anyway.
    test()->postJson(route('projects.store'), [
        'organization' => $organization->slug,
        'name' => 'Third project',
        'idempotency_token' => 'journey-three',
    ])->assertUnprocessable()->assertJsonPath('upgradePlan', 'Pro');

    $page->click('Upgrade to Pro')
        ->assertPathIs('/organizations/billing')
        ->assertSee('Pro')
        ->press('Subscribe to Pro')
        ->assertPathIsNot('/organizations/billing');

    StripeWebhook::reportSubscription($this->provider, 'cus_journey', 'sub_journey', 'si_journey');

    // The ceiling moved because the refresh read the provider, not because the test
    // wrote an allowance.
    expect($this->provider->requested)->toContain('get /v1/subscriptions');

    visit('/projects')
        ->assertSee('2 of 10 projects used')
        ->assertDontSee('You have used every project on this plan')
        ->type('name', 'Third project')
        ->press('Create project')
        ->assertSee('Third project')
        ->assertSee('3 of 10 projects used')
        ->assertNoJavaScriptErrors();

    expect(resolve(TenantContext::class)->runFor($organization, fn (): int => Project::query()->count()))
        ->toBe(3);
});
