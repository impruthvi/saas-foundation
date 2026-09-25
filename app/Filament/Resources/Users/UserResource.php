<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\MembershipRepository;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * People with an account. Read-only, with impersonation on the view page.
 */
final class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'email';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')
                ->columns(3)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email')->copyable(),
                    TextEntry::make('email_verified_at')->label('Verified')->dateTime()->placeholder('Not verified'),
                    IconEntry::make('two_factor')
                        ->label('Two-factor')
                        ->boolean()
                        ->state(fn (User $record): bool => $record->two_factor_confirmed_at !== null),
                    TextEntry::make('created_at')->dateTime(),
                ]),
            Section::make('Organizations')
                ->schema([
                    RepeatableEntry::make('organizations')
                        ->hiddenLabel()
                        ->placeholder('None')
                        ->state(fn (User $record): array => resolve(MembershipRepository::class)
                            ->organizationsFor($record)
                            ->map(fn (Organization $organization): array => [
                                'name' => $organization->name,
                                'slug' => $organization->slug,
                                'personal' => $organization->personal,
                                'owner' => $organization->owner_id === $record->id,
                            ])
                            ->values()
                            ->all())
                        ->columns(4)
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('slug'),
                            IconEntry::make('personal')->boolean(),
                            IconEntry::make('owner')->boolean(),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable()->copyable(),
                IconColumn::make('email_verified_at')->label('Verified')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
