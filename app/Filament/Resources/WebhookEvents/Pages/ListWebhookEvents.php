<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookEvents\Pages;

use App\Filament\Resources\WebhookEvents\WebhookEventResource;
use Filament\Resources\Pages\ListRecords;

final class ListWebhookEvents extends ListRecords
{
    protected static string $resource = WebhookEventResource::class;
}
