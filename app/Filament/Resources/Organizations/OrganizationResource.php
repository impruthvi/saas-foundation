<?php

declare(strict_types=1);

namespace App\Filament\Resources\Organizations;

use App\Enums\OrganizationStatus;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Models\Organization;
use App\Operations\LookupOrganizations;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @return Builder<Organization>
     */
    public static function getEloquentQuery(): Builder
    {
        return Organization::query()->with('owner');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Organization $record): string => $record->slug)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereIn(
                        'id',
                        resolve(LookupOrganizations::class)->matching($search),
                    )),
                TextColumn::make('owner.email')
                    ->label('Owner'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrganizationStatus $state): string => ucfirst($state->value)),
                IconColumn::make('personal')
                    ->boolean(),
                TextColumn::make('stripe_id')
                    ->label('Stripe customer')
                    ->placeholder('None')
                    ->copyable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'view' => ViewOrganization::route('/{record}'),
        ];
    }
}
