<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookEvents;

use App\Actions\ReplayWebhookEvent;
use App\Enums\WebhookOutcome;
use App\Filament\Resources\WebhookEvents\Pages\ListWebhookEvents;
use App\Filament\Resources\WebhookEvents\Pages\ViewWebhookEvent;
use App\Models\WebhookEvent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Every Stripe delivery, including the ones no organization could claim.
 */
final class WebhookEventResource extends Resource
{
    protected static ?string $model = WebhookEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $recordTitleAttribute = 'stripe_event_id';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function replayAction(): Action
    {
        return Action::make('replay')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalDescription('Applies this event as if Stripe had just delivered it. The replay is audited.')
            ->visible(fn (WebhookEvent $record): bool => in_array($record->outcome, [WebhookOutcome::Unplaceable, WebhookOutcome::Errored], true))
            ->action(function (WebhookEvent $record): void {
                $outcome = resolve(ReplayWebhookEvent::class)->handle($record);

                Notification::make()
                    ->title('Replay finished: '.$outcome->value)
                    ->status($outcome === WebhookOutcome::Replayed ? 'success' : 'warning')
                    ->send();
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Delivery')
                ->columns(3)
                ->schema([
                    TextEntry::make('stripe_event_id')->label('Event')->copyable()->placeholder('None'),
                    TextEntry::make('type')->placeholder('None'),
                    TextEntry::make('outcome')->badge(),
                    TextEntry::make('outcome_reason')->label('Reason')->placeholder('None'),
                    TextEntry::make('outcome_message')->label('Message')->placeholder('None'),
                    TextEntry::make('stripe_customer_id')->label('Stripe customer')->placeholder('None')->copyable(),
                    TextEntry::make('applied_at')->label('Applied')->dateTime()->placeholder('Not applied'),
                    TextEntry::make('deliveries'),
                    TextEntry::make('last_received_at')->label('Last received')->dateTime(),
                ]),
            Section::make('Payload')
                ->collapsed()
                ->schema([
                    TextEntry::make('payload')
                        ->hiddenLabel()
                        ->fontFamily(FontFamily::Mono)
                        ->state(fn (WebhookEvent $record): string => (string) json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                        ->copyable(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_received_at', 'desc')
            ->columns([
                TextColumn::make('type')->searchable(),
                TextColumn::make('outcome')->badge(),
                TextColumn::make('outcome_reason')->label('Reason')->placeholder('None'),
                TextColumn::make('stripe_customer_id')->label('Stripe customer')->searchable()->placeholder('None'),
                TextColumn::make('deliveries'),
                TextColumn::make('last_received_at')->label('Last received')->dateTime()->sortable(),
                TextColumn::make('stripe_event_id')->label('Event')->searchable()->copyable(),
            ])
            ->filters([
                SelectFilter::make('outcome')
                    ->options(collect(WebhookOutcome::cases())->mapWithKeys(fn (WebhookOutcome $outcome): array => [$outcome->value => ucfirst($outcome->value)])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::replayAction(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookEvents::route('/'),
            'view' => ViewWebhookEvent::route('/{record}'),
        ];
    }
}
