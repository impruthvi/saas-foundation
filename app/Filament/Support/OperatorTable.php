<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Contracts\Operators;
use App\Models\Operator;
use App\Models\User;
use Filament\Facades\Filament;

final readonly class OperatorTable implements Operators
{
    public function isOperator(User $user): bool
    {
        return Operator::query()->where('user_id', $user->id)->exists();
    }

    public function returnUrl(): string
    {
        return Filament::getPanel('admin')->getUrl() ?? url('/admin');
    }
}
