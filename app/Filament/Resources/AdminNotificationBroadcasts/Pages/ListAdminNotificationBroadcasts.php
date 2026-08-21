<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdminNotificationBroadcasts\Pages;

use App\Filament\Resources\AdminNotificationBroadcasts\AdminNotificationBroadcastResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAdminNotificationBroadcasts extends ListRecords
{
    protected static string $resource = AdminNotificationBroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('إرسال إشعار جديد')];
    }
}
