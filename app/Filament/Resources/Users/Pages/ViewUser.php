<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Actions\StartImpersonation;
use App\Exceptions\ImpersonationRefused;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * @property User $record
 */
final class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('impersonate')
                ->label('Act as this user')
                ->icon(Heroicon::OutlinedUserCircle)
                ->color('warning')
                ->visible(fn (User $record): bool => ! $record->is(Auth::user()))
                ->modalDescription('You will use the product as this person for up to '.StartImpersonation::MINUTES.' minutes. Credential, billing and invitation changes stay refused, and everything you do is recorded against this impersonation.')
                ->schema([
                    Textarea::make('reason')
                        ->label('Why')
                        ->required()
                        ->maxLength(1000),
                ])
                ->action(function (array $data, User $record): void {
                    $operator = Auth::user();

                    if (! $operator instanceof User) {
                        return;
                    }

                    try {
                        resolve(StartImpersonation::class)->handle($operator, $record, (string) $data['reason'], session()->driver());
                    } catch (ImpersonationRefused $impersonationRefused) {
                        Notification::make()->title($impersonationRefused->getMessage())->danger()->send();

                        return;
                    }

                    $this->redirect(route('dashboard'));
                }),
        ];
    }
}
