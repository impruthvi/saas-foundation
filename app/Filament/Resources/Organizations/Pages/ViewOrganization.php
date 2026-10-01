<?php

declare(strict_types=1);

namespace App\Filament\Resources\Organizations\Pages;

use App\Actions\GrantEntitlementOverride;
use App\Actions\RequestEntitlementRefresh;
use App\Actions\RevokeEntitlementOverride;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\Organizations\Widgets\OrganizationAuditLog;
use App\Filament\Resources\Organizations\Widgets\SubscriptionEvents;
use App\Models\Organization;
use App\Models\User;
use App\Operations\InspectEntitlements;
use App\Operations\OrganizationActivity;
use App\Operations\SubscriptionTimeline;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Date;

/**
 * Nothing here queries a tenant-owned model directly; every value comes from a read
 * service that resolves this organization.
 *
 * @property Organization $record
 */
final class ViewOrganization extends ViewRecord
{
    protected static string $resource = OrganizationResource::class;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $inspection = null;

    /**
     * @var array{state: string, plan: string|null, stripe_status: string|null, ends_at: string|null}|null
     */
    private ?array $subscription = null;

    /**
     * @var array{stripe_event_id: string|null, type: string|null, applied_at: string|null}|false|null
     */
    private array|false|null $planSetBy = false;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Organization')
                ->columns(3)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('slug'),
                    TextEntry::make('owner.email')->label('Owner'),
                    TextEntry::make('status')->state(fn (Organization $record): string => ucfirst($record->status->value))->badge(),
                    IconEntry::make('personal')->boolean(),
                    TextEntry::make('stripe_id')->label('Stripe customer')->placeholder('None')->copyable(),
                ]),
            Section::make('Entitlements')
                ->description('What this organization may do, where each answer came from, and what asked for it.')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('plan')->label('Resolved plan')->placeholder('Unknown')->state(fn (): ?string => $this->inspection()['plan']),
                        TextEntry::make('refresh_status')->label('Refresh')->badge()->state(fn (): string => $this->inspection()['refresh']['status']),
                        TextEntry::make('observed_at')->label('Observed')->dateTime()->placeholder('Never')->state(fn (): ?string => $this->inspection()['refresh']['observed_at']),
                        IconEntry::make('stale')->boolean()->state(fn (): bool => $this->inspection()['refresh']['stale']),
                    ]),
                    TextEntry::make('last_error')->placeholder('None')->state(fn (): ?string => $this->inspection()['refresh']['last_error']),
                    RepeatableEntry::make('features')
                        ->state(fn (): array => $this->inspection()['features'])
                        ->columns(4)
                        ->schema([
                            TextEntry::make('feature'),
                            TextEntry::make('allowance')->formatStateUsing(fn (mixed $state): string => match (true) {
                                $state === null => 'Unlimited',
                                is_bool($state) => $state ? 'Yes' : 'No',
                                default => (string) $state,
                            }),
                            TextEntry::make('source')->badge(),
                            TextEntry::make('usage')->placeholder('Not metered'),
                        ]),
                    RepeatableEntry::make('overrides')
                        ->label('Active overrides')
                        ->state(fn (): array => $this->inspection()['overrides'])
                        ->columns(5)
                        ->schema([
                            TextEntry::make('id')->label('Grant'),
                            TextEntry::make('feature'),
                            TextEntry::make('allowance'),
                            TextEntry::make('expires_at')->label('Expires')->dateTime(),
                            TextEntry::make('reason'),
                        ]),
                    TextEntry::make('plan_set_by')
                        ->label('Plan set by')
                        ->placeholder('No Stripe event has changed the subscription')
                        ->state(fn (): ?string => ($event = $this->planSetBy()) === null
                            ? null
                            : ($event['type'] ?? 'Unrecorded event').' · '.$event['stripe_event_id']),
                    TextEntry::make('trigger')
                        ->label('Last refresh requested by')
                        ->state(fn (): string => match ($this->inspection()['trigger']['kind']) {
                            'never' => 'Never refreshed',
                            'pending' => 'A refresh is waiting to run',
                            'none' => 'No Stripe event: a scheduled sweep, a recovery, or an operator',
                            default => 'These Stripe events, received in the same second',
                        }),
                    RepeatableEntry::make('trigger_events')
                        ->hiddenLabel()
                        ->visible(fn (): bool => $this->inspection()['trigger']['events'] !== [])
                        ->state(fn (): array => $this->inspection()['trigger']['events'])
                        ->columns(4)
                        ->schema([
                            TextEntry::make('stripe_event_id')->label('Event')->copyable(),
                            TextEntry::make('type')->placeholder('Not recorded'),
                            TextEntry::make('applied_at')->label('Applied')->dateTime()->placeholder('Not applied'),
                            IconEntry::make('primary')->boolean(),
                        ]),
                ]),
            Section::make('Subscription')
                ->columns(4)
                ->schema([
                    TextEntry::make('subscription_state')->label('State')->badge()->state(fn (): string => $this->subscriptionSummary()['state']),
                    TextEntry::make('subscription_plan')->label('Plan')->placeholder('None')->state(fn (): ?string => $this->subscriptionSummary()['plan']),
                    TextEntry::make('stripe_status')->label('Stripe status')->placeholder('None')->state(fn (): ?string => $this->subscriptionSummary()['stripe_status']),
                    TextEntry::make('ends_at')->label('Ends')->dateTime()->placeholder('Not ending')->state(fn (): ?string => $this->subscriptionSummary()['ends_at']),
                ]),
            Section::make('Members')
                ->schema([
                    RepeatableEntry::make('members')
                        ->hiddenLabel()
                        ->state(fn (Organization $record): array => resolve(OrganizationActivity::class)->members($record))
                        ->columns(5)
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('email'),
                            TextEntry::make('rank')->badge(),
                            TextEntry::make('status')->badge(),
                            IconEntry::make('owner')->boolean(),
                        ]),
                ]),
            Section::make('Pending invitations')
                ->collapsed()
                ->schema([
                    RepeatableEntry::make('invitations')
                        ->hiddenLabel()
                        ->placeholder('None')
                        ->state(fn (Organization $record): array => resolve(OrganizationActivity::class)->pendingInvitations($record))
                        ->columns(4)
                        ->schema([
                            TextEntry::make('email'),
                            TextEntry::make('rank')->badge(),
                            TextEntry::make('expires_at')->dateTime(),
                            IconEntry::make('expired')->boolean(),
                        ]),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestRefresh')
                ->label('Request entitlement refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalDescription("Works this organization's entitlements out again from its billing facts. The request is audited.")
                ->action(function (Organization $record): void {
                    resolve(RequestEntitlementRefresh::class)->handle($record);

                    Notification::make()->title('Refresh requested')->success()->send();
                }),
            Action::make('grantOverride')
                ->label('Grant allowance override')
                ->schema([
                    Select::make('feature')
                        ->options(fn (): array => $this->numericFeatureOptions())
                        ->required(),
                    TextInput::make('allowance')->integer()->minValue(0)->required(),
                    DateTimePicker::make('expires_at')->label('Expires')->after('now')->required(),
                    Textarea::make('reason')->required()->maxLength(500),
                ])
                ->action(function (Organization $record, array $data): void {
                    $operator = auth()->user();
                    abort_unless($operator instanceof User, 403);

                    resolve(GrantEntitlementOverride::class)->handle(
                        $record,
                        $operator,
                        (string) $data['feature'],
                        (int) $data['allowance'],
                        (string) $data['reason'],
                        Date::parse((string) $data['expires_at'])->toDateTimeImmutable(),
                    );

                    $this->inspection = null;
                    Notification::make()->title('Override granted')->success()->send();
                }),
            Action::make('revokeOverride')
                ->label('Revoke allowance override')
                ->visible(fn (): bool => $this->inspection()['overrides'] !== [])
                ->schema([
                    Select::make('grant_id')->label('Grant')->options(fn (): array => $this->activeOverrideOptions())->required(),
                    Textarea::make('reason')->required()->maxLength(500),
                ])
                ->action(function (Organization $record, array $data): void {
                    $operator = auth()->user();
                    abort_unless($operator instanceof User, 403);

                    resolve(RevokeEntitlementOverride::class)->handle(
                        $record,
                        $operator,
                        (int) $data['grant_id'],
                        (string) $data['reason'],
                    );

                    $this->inspection = null;
                    Notification::make()->title('Override revoked')->success()->send();
                }),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            SubscriptionEvents::class,
            OrganizationAuditLog::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inspection(): array
    {
        return $this->inspection ??= resolve(InspectEntitlements::class)->for($this->record);
    }

    /** @return array<int, string> */
    private function activeOverrideOptions(): array
    {
        $options = [];

        foreach ($this->inspection()['overrides'] as $override) {
            $options[$override['id']] = "#{$override['id']} {$override['feature']}: {$override['allowance']} until {$override['expires_at']}";
        }

        return $options;
    }

    /** @return array<string, string> */
    private function numericFeatureOptions(): array
    {
        $options = [];

        foreach ($this->inspection()['features'] as $feature) {
            if ($feature['usage'] !== null) {
                $options[$feature['feature']] = $feature['feature'];
            }
        }

        return $options;
    }

    /**
     * @return array{stripe_event_id: string|null, type: string|null, applied_at: string|null}|null
     */
    private function planSetBy(): ?array
    {
        if ($this->planSetBy === false) {
            $this->planSetBy = resolve(SubscriptionTimeline::class)->currentStateEvent($this->record);
        }

        return $this->planSetBy;
    }

    /**
     * @return array{state: string, plan: string|null, stripe_status: string|null, ends_at: string|null}
     */
    private function subscriptionSummary(): array
    {
        return $this->subscription ??= resolve(SubscriptionTimeline::class)->current($this->record);
    }
}
