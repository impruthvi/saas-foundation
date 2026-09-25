<?php

declare(strict_types=1);

namespace App\Filament\Resources\Organizations\Widgets;

use App\Actions\ReplayWebhookEvent;
use App\Enums\WebhookOutcome;
use App\Filament\Support\PagedRows;
use App\Models\Organization;
use App\Models\WebhookEvent;
use App\Operations\SubscriptionTimeline;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use LogicException;

/**
 * The organization's Stripe events, newest first by Stripe's own clock.
 *
 * Cashier keeps only the current subscription row, so this is its history.
 */
final class SubscriptionEvents extends TableWidget
{
    public ?Organization $record = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Stripe events';

    public function table(Table $table): Table
    {
        return $table
            ->queryStringIdentifier('events')
            ->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
                $organization = $this->record ?? throw new LogicException('This widget belongs on an organization page.');
                $events = resolve(SubscriptionTimeline::class)->events($organization, $page, $recordsPerPage);

                return PagedRows::paginate($events, $page, $recordsPerPage, 'eventsPage');
            })
            ->columns([
                TextColumn::make('type'),
                TextColumn::make('outcome')->badge(),
                TextColumn::make('outcome_reason')->label('Reason')->placeholder('None'),
                TextColumn::make('applied_at')->label('Applied')->dateTime()->placeholder('Not applied'),
                IconColumn::make('wrote_current_state')->label('Current state')->boolean(),
                TextColumn::make('deliveries'),
                TextColumn::make('stripe_event_id')->label('Event')->copyable(),
            ])
            ->recordActions([
                Action::make('replay')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalDescription('Applies this event as if Stripe had just delivered it. The replay is audited.')
                    ->visible(fn (array $record): bool => in_array($record['outcome'], [WebhookOutcome::Unplaceable->value, WebhookOutcome::Errored->value], true))
                    ->action(function (array $record): void {
                        $outcome = resolve(ReplayWebhookEvent::class)->handle(WebhookEvent::query()->whereKey($record['id'])->firstOrFail());

                        Notification::make()
                            ->title('Replay finished: '.$outcome->value)
                            ->status($outcome === WebhookOutcome::Replayed ? 'success' : 'warning')
                            ->send();

                        $this->resetTable();
                    }),
            ]);
    }
}
