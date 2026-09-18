<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Pages;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningNeighborhood;
use Modules\Cleaning\Support\WorkerRoomAssignmentPlanner;

final class EditCleaningBooking extends EditRecord
{
    protected static string $resource = CleaningBookingResource::class;

    /** @var list<string> */
    private array $selectedRoomKeys = [];

    private bool $shouldSyncSelectedRooms = false;

    public function getTitle(): string
    {
        return 'تعديل حجز تنظيف';
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('عرض الحجز'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $roomKeys = $this->record->rooms()
            ->orderBy('id')
            ->pluck('room_key')
            ->map(static fn (mixed $key): string => (string) $key)
            ->all();

        if ($roomKeys === []) {
            $details = is_array($this->record->property_details) ? $this->record->property_details : [];
            $roomKeys = array_values(array_map(
                static fn (array $room): string => (string) $room['room_key'],
                WorkerRoomAssignmentPlanner::generateRoomBlueprints($details),
            ));
        }

        $data['selected_room_keys'] = $roomKeys;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('selected_room_keys', $data)) {
            $this->selectedRoomKeys = array_values(array_unique(array_map(
                static fn (mixed $key): string => (string) $key,
                (array) $data['selected_room_keys'],
            )));
            $this->shouldSyncSelectedRooms = true;
            unset($data['selected_room_keys']);
        }

        if (filled($data['neighborhood_id'] ?? null)) {
            $neighborhood = CleaningNeighborhood::query()->find((int) $data['neighborhood_id']);

            if ($neighborhood instanceof CleaningNeighborhood) {
                $data['neighborhood_name'] = $neighborhood->name_ar ?: $neighborhood->name_en;
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if (! $this->shouldSyncSelectedRooms || ! $this->record instanceof CleaningBooking) {
            return;
        }

        $this->syncSelectedRooms($this->record);
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->title('تم تحديث الحجز والغرف المختارة')
            ->success();
    }

    private function syncSelectedRooms(CleaningBooking $booking): void
    {
        $details = is_array($booking->property_details) ? $booking->property_details : [];
        $blueprints = collect(WorkerRoomAssignmentPlanner::generateRoomBlueprints($details))
            ->keyBy(fn (array $room): string => (string) $room['room_key']);

        if ($blueprints->isEmpty()) {
            return;
        }

        $selected = array_values(array_filter(
            $this->selectedRoomKeys,
            fn (string $key): bool => $blueprints->has($key),
        ));

        if ($selected === []) {
            return;
        }

        DB::transaction(function () use ($booking, $blueprints, $selected): void {
            $booking->rooms()
                ->whereNotIn('room_key', $selected)
                ->delete();

            $existing = $booking->rooms()
                ->pluck('room_key')
                ->map(static fn (mixed $key): string => (string) $key)
                ->all();

            foreach ($selected as $roomKey) {
                if (in_array($roomKey, $existing, true)) {
                    continue;
                }

                $room = $blueprints->get($roomKey);
                if (! is_array($room)) {
                    continue;
                }

                $booking->rooms()->create([
                    'room_key' => (string) $room['room_key'],
                    'room_type' => (string) $room['room_type'],
                    'room_size' => (string) $room['room_size'],
                    'display_label' => (string) $room['display_label'],
                    'weight' => (float) $room['weight'],
                ]);
            }
        });

        $booking->unsetRelation('rooms');
    }
}
