<?php

declare(strict_types=1);

use App\Actions\CreateProject;
use App\Billing\PlanCatalog;
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
 * Pb1: refused at the limit, through Checkout, allowed — in a real browser.
 *
 * It lives in the committed lane rather than beside the other browser tests
 * because the refusal is only lifted by a refresh, and a refresh refuses to run
 * inside a transaction. `tests/Browser/ProjectLimitTest.php` keeps the parts
 * that need no refresh and so run on SQLite too.
 *
 * Two fakes, at two different depths, because Stripe is reached two different
 * ways here. Checkout goes through the client Cashier hands out, which
 * `FakeStripeClient` replaces. The entitlement refresh builds its own client
 * and can only be reached under it — see `FakeStripeApi`.
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

    // Refused, and the screen says so rather than pretending otherwise.
    $page = visit('/projects');

    $page->assertSee('2 of 2 projects used')
        ->assertSee('You have used every project on this plan')
        ->assertSee('Upgrade to Pro')
        ->assertNoJavaScriptErrors();

    // The prompt is a projection, not the limit. Hiding the button changes
    // nothing about what the endpoint does with a request that arrives anyway.
    test()->postJson(route('projects.store'), [
        'organization' => $organization->slug,
        'name' => 'Third project',
        'idempotency_token' => 'journey-three',
    ])->assertUnprocessable()->assertJsonPath('upgradePlan', 'Pro');

    // Through checkout, from the prompt the refusal put on the screen.
    $page->click('Upgrade to Pro')
        ->assertPathIs('/organizations/billing')
        ->assertSee('Pro')
        ->press('Subscribe to Pro')
        ->assertPathIsNot('/organizations/billing');

    // Stripe now reports the subscription, and says so by webhook.
    $this->provider->withActiveSubscription(
        'cus_journey',
        (string) resolve(PlanCatalog::class)->findPlan('pro')?->prices[0]->id,
        'sub_journey',
    );

    acrossEveryOwner(fn () => StripeWebhook::post(StripeWebhook::subscriptionPayload(
        customerId: 'cus_journey',
        created: 1_000,
        subscriptionId: 'sub_journey',
        itemId: 'si_journey',
    ))->assertOk());

    // The ceiling moved because the refresh read the provider, not because
    // anything in this test wrote an allowance into the store.
    expect($this->provider->requested)->toContain('get /v1/subscriptions');

    // The same project the Free plan refused, created through the same form.
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
