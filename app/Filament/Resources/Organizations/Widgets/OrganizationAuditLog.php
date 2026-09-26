<?php

declare(strict_types=1);

namespace App\Filament\Resources\Organizations\Widgets;

use App\Filament\Support\PagedRows;
use App\Models\Organization;
use App\Operations\OrganizationActivity;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use LogicException;

/**
 * What has been done in the organization, and by whom, newest first.
 */
final class OrganizationAuditLog extends TableWidget
{
    public ?Organization $record = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Audit log';

    public function table(Table $table): Table
    {
        return $table
            ->queryStringIdentifier('audit')
            ->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
                $organization = $this->record ?? throw new LogicException('This widget belongs on an organization page.');
                $events = resolve(OrganizationActivity::class)->auditLog($organization, $page, $recordsPerPage);

                return PagedRows::paginate($events, $page, $recordsPerPage, 'auditPage');
            })
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime(),
                TextColumn::make('action')->badge(),
                TextColumn::make('actor')->placeholder('Nobody'),
                TextColumn::make('impersonated_by')->label('Impersonated by')->placeholder('None'),
                TextColumn::make('source')->badge(),
                TextColumn::make('context')
                    ->state(fn (array $record): string => collect(is_array($record['context'] ?? null) ? $record['context'] : [])
                        ->map(fn (mixed $value, string $key): string => $key.': '.(is_scalar($value) ? (string) $value : (string) json_encode($value)))
                        ->implode(', '))
                    ->placeholder('None')
                    ->limit(80),
            ]);
    }
}
