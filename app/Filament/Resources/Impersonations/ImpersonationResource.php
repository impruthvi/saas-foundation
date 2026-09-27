<?php

declare(strict_types=1);

namespace App\Filament\Resources\Impersonations;

use App\Enums\ImpersonationEnd;
use App\Filament\Resources\Impersonations\Pages\ListImpersonations;
use App\Models\Impersonation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ImpersonationResource extends Resource
{
    protected static ?string $model = Impersonation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @return Builder<Impersonation>
     */
    public static function getEloquentQuery(): Builder
    {
        return Impersonation::query()->with(['operator', 'user']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('operator.email')->label('Operator')->placeholder('Deleted account'),
                TextColumn::make('user.email')->label('Acted as')->placeholder('Deleted account'),
                TextColumn::make('reason')->limit(60)->wrap(),
                TextColumn::make('started_at')->dateTime()->sortable(),
                TextColumn::make('expires_at')->dateTime(),
                TextColumn::make('ended_at')->dateTime()->placeholder('Live'),
                TextColumn::make('ended_by')
                    ->badge()
                    ->formatStateUsing(fn (?ImpersonationEnd $state): string => ! $state instanceof ImpersonationEnd ? '' : ucfirst($state->value)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImpersonations::route('/'),
        ];
    }
}
