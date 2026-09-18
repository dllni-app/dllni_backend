<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Pages;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Cleaning\Models\CleaningNeighborhood;

final class EditCleaningBooking extends EditRecord
{
    protected static string $resource = CleaningBookingResource::class;

    public function getTitle(): string
    {
        return 'تعديل حجز تنظيف';
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('عرض'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['property_details_json'] = $this->encodeJson($data['property_details'] ?? []);
        $data['cleaning_services_json'] = $this->encodeJson($data['cleaning_services'] ?? []);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['property_details'] = $this->decodeJson($data['property_details_json'] ?? null);
        $data['cleaning_services'] = $this->decodeJson($data['cleaning_services_json'] ?? null);
        unset($data['property_details_json'], $data['cleaning_services_json']);

        if (filled($data['neighborhood_id'] ?? null)) {
            $neighborhood = CleaningNeighborhood::query()->find((int) $data['neighborhood_id']);
            if ($neighborhood instanceof CleaningNeighborhood) {
                $data['neighborhood_name'] = $neighborhood->name_ar ?: $neighborhood->name_en;
            }
        }

        return $data;
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()->title('تم تحديث معلومات الحجز')->success();
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode($value ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function decodeJson(mixed $value): array
    {
        if (! is_string($value) || mb_trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
