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

    /** @var list<string> */
    private const JSON_FIELDS = [
        'property_details',
        'event_dynamic_answers',
        'cleaning_services',
        'open_time_extension_options',
        'worker_finished_cleaning_services',
        'worker_finished_property_rooms',
    ];

    public function getTitle(): string
    {
        return 'تعديل كامل حجز التنظيف';
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('عرض الحجز'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach (self::JSON_FIELDS as $field) {
            $data[$field.'_json'] = $this->encodeJson($data[$field] ?? []);
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (self::JSON_FIELDS as $field) {
            $data[$field] = $this->decodeJson($data[$field.'_json'] ?? null);
            unset($data[$field.'_json']);
        }

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
        return Notification::make()
            ->title('تم تحديث معلومات الحجز')
            ->success();
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode(
            $value ?? [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) ?: '[]';
    }

    /** @return array<mixed> */
    private function decodeJson(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
