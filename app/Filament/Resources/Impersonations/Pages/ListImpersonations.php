<?php

declare(strict_types=1);

namespace App\Filament\Resources\Impersonations\Pages;

use App\Filament\Resources\Impersonations\ImpersonationResource;
use Filament\Resources\Pages\ListRecords;

final class ListImpersonations extends ListRecords
{
    protected static string $resource = ImpersonationResource::class;
}
