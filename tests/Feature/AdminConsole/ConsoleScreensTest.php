<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMember;
use App\Actions\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\WebhookOutcome;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Resources\Organizations\Widgets\OrganizationAuditLog;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\WebhookEvents\Pages\ListWebhookEvents;
use App\Filament\Widgets\EntitlementHealth;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Impersonation;
use App\Models\Operator;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Impruthvi\CashierEntitlements\Jobs\RefreshOwner;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * Selected the way the web group resolves it on a Livewire update.
 *
 * @return array{0: User, 1: Organization}
 */
function operatorWithAnOrganizationOfTheirOwn(): array
{
    [$organization, $operator] = organizationOwnedBySomeone('Operator Co');
    Operator::factory()->create(['user_id' => $operator->id]);
    resolve(RecordAuditEvent::class)->handle($organization->id, AuditAction::InvitationSent, context: ['email' => 'operators-own@example.com']);

    test()->actingAs($operator)->withSession([ResolveTenantContext::SESSION_KEY => $organization->id]);
    resolve(TenantContext::class)->set($organization);

    return [$operator, $organization];
}

it('serves every console page to an operator', function (): void {
    [$operator] = operatorWithAnOrganizationOfTheirOwn();
    [$customer] = organizationOwnedBySomeone('Customer');
    $event = WebhookEvent::factory()->create();

    foreach ([
        '/admin/organizations',
        "/admin/organizations/{$customer->getRouteKey()}",
        '/admin/users',
        "/admin/users/{$operator->getRouteKey()}",
        '/admin/webhook-events',
        "/admin/webhook-events/{$event->id}",
        '/admin/impersonations',
    ] as $url) {
        $this->get($url)->assertOk();
    }

    acrossEveryOwner(function (): void {
        $this->get('/admin')->assertOk();

        Livewire::withoutLazyLoading()->test(EntitlementHealth::class)->assertSee('Organizations tracked');
    });
});

it('offers nothing to create, edit or delete', function (): void {
    foreach (['organizations', 'users', 'webhook-events', 'impersonations'] as $resource) {
        expect(Route::has("filament.admin.resources.{$resource}.create"))->toBeFalse()
            ->and(Route::has("filament.admin.resources.{$resource}.edit"))->toBeFalse();
    }
});

it('finds an organization by name, slug, Stripe customer or a member address', function (string $search): void {
    operatorWithAnOrganizationOfTheirOwn();
    [$acme] = organizationOwnedBySomeone('Acme Rockets');
    $acme->forceFill(['stripe_id' => 'cus_acme'])->save();
    resolve(AddOrganizationMember::class)->handle($acme, User::factory()->create(['email' => 'bob@example.com']));
    [$globex] = organizationOwnedBySomeone('Globex');

    Livewire::test(ListOrganizations::class)
        ->searchTable($search)
        ->assertCanSeeTableRecords([$acme])
        ->assertCanNotSeeTableRecords([$globex]);
})->with([
    'name' => ['Acme Rock'],
    'slug' => ['acme-rockets'],
    'Stripe customer' => ['cus_acme'],
    'member address' => ['bob@example.com'],
]);

it('shows the customer organization, never the operator own, while the operator own is resolved', function (): void {
    [, $operatorOrganization] = operatorWithAnOrganizationOfTheirOwn();
    [$customer, $customerOwner] = organizationOwnedBySomeone('Customer');
    resolve(RecordAuditEvent::class)->handle($customer->id, AuditAction::InvitationSent, context: ['email' => 'customers-own@example.com']);

    expect(resolve(TenantContext::class)->id())->toBe($operatorOrganization->id);

    Livewire::test(ViewOrganization::class, ['record' => $customer->getRouteKey()])
        ->assertOk()
        ->assertSee($customerOwner->email);

    Livewire::withoutLazyLoading()->test(OrganizationAuditLog::class, ['record' => $customer])
        ->call('loadTable')
        ->assertSee('customers-own@example.com')
        ->assertDontSee('operators-own@example.com');
});

it('requests an entitlement refresh from the organization page', function (): void {
    operatorWithAnOrganizationOfTheirOwn();
    [$customer] = organizationOwnedBySomeone('Customer');
    Bus::fake([RefreshOwner::class]);

    Livewire::test(ViewOrganization::class, ['record' => $customer->getRouteKey()])
        ->callAction('requestRefresh')
        ->assertNotified('Refresh requested');

    Bus::assertDispatched(RefreshOwner::class);
});

it('starts an impersonation from the user page and lands in the product as that user', function (): void {
    [$operator] = operatorWithAnOrganizationOfTheirOwn();
    [, $alice] = organizationOwnedBySomeone('Alice');

    Livewire::test(ViewUser::class, ['record' => $alice->getRouteKey()])
        ->callAction('impersonate', data: ['reason' => 'Ticket 88'])
        ->assertRedirect(route('dashboard'));

    expect(Auth::id())->toBe($alice->id)
        ->and(Impersonation::query()->sole())->operator_id->toBe($operator->id)->reason->toBe('Ticket 88');
});

it('explains a refused impersonation instead of starting one', function (): void {
    operatorWithAnOrganizationOfTheirOwn();
    $otherOperator = User::factory()->create();
    Operator::factory()->create(['user_id' => $otherOperator->id]);

    Livewire::test(ViewUser::class, ['record' => $otherOperator->getRouteKey()])
        ->callAction('impersonate', data: ['reason' => 'Curious'])
        ->assertNotified('Operators cannot act as other operators.')
        ->assertNoRedirect();

    expect(Impersonation::query()->count())->toBe(0);
});

it('requires a reason to impersonate', function (): void {
    operatorWithAnOrganizationOfTheirOwn();
    [, $alice] = organizationOwnedBySomeone('Alice');

    Livewire::test(ViewUser::class, ['record' => $alice->getRouteKey()])
        ->callAction('impersonate', data: ['reason' => ''])
        ->assertHasFormErrors(['reason' => 'required']);
});

it('offers replay only for an event that never applied, and replays it', function (): void {
    operatorWithAnOrganizationOfTheirOwn();
    $applied = WebhookEvent::factory()->create();
    $unplaceable = WebhookEvent::factory()->unplaceable()->create(['stripe_customer_id' => 'cus_nobody']);

    Livewire::test(ListWebhookEvents::class)
        ->assertActionHidden(TestAction::make('replay')->table($applied))
        ->assertActionVisible(TestAction::make('replay')->table($unplaceable))
        ->callAction(TestAction::make('replay')->table($unplaceable))
        ->assertNotified();

    expect($unplaceable->fresh()?->outcome)->toBe(WebhookOutcome::Refused);
});
