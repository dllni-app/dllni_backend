<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningRoomTypes\Pages;

use App\Filament\Resources\CleaningRoomTypes\CleaningRoomTypeResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningRoomType;

final class EditCleaningRoomType extends EditRecord
{
    protected static string $resource = CleaningRoomTypeResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Never switch the catalog to a zero-priced replacement.
        if ($this->record?->code === 'room' &&
            (bool) ($data['is_active'] ?? false) &&
            ((float) ($data['regular_hourly_rate'] ?? 0) <= 0 ||
                (float) ($data['deep_hourly_rate'] ?? 0) <= 0)) {
            throw ValidationException::withMessages([
                'data.regular_hourly_rate' => 'حدّد سعراً أكبر من صفر للحوسة والتعزيل قبل إظهار «غرف» في التطبيق.',
            ]);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $room = $this->record;
        if (! $room instanceof CleaningRoomType ||
            $room->code !== 'room' ||
            ! $room->is_active ||
            (float) $room->regular_hourly_rate <= 0 ||
            (float) $room->deep_hourly_rate <= 0) {
            return;
        }

        // Retire only the old selectable options. All past bookings retain
        // their stable codes and historical price snapshots.
        CleaningRoomType::query()
            ->whereIn('code', ['bedroom', 'living_room'])
            ->update(['is_active' => false]);
    }
}
