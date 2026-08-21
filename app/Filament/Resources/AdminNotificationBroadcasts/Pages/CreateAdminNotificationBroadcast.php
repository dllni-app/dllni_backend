<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdminNotificationBroadcasts\Pages;

use App\Filament\Resources\AdminNotificationBroadcasts\AdminNotificationBroadcastResource;
use App\Jobs\DispatchAdminNotificationBroadcast;
use App\Models\AdminNotificationBroadcast;
use Filament\Resources\Pages\CreateRecord;

final class CreateAdminNotificationBroadcast extends CreateRecord
{
    protected static string $resource = AdminNotificationBroadcastResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['audience_type'] ?? null) !== AdminNotificationBroadcast::AUDIENCE_MODULE_TYPES) {
            $data['module_types'] = null;
        }

        $data['created_by_user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->record->audience_type !== AdminNotificationBroadcast::AUDIENCE_SPECIFIC_USERS) {
            $this->record->users()->detach();
        }

        DispatchAdminNotificationBroadcast::dispatch((int) $this->record->id)->afterCommit();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
