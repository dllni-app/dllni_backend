<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;

/** Extend existing custody windows only, never assign new assets during work. */
final class CleaningV3EquipmentResumeGuard
{
    public function extend(CleaningBookingSession $visit, CarbonImmutable $until): void
    {
        $statuses = ['reserved', 'handed_over', 'acknowledged', 'return_pending_confirmation', 'failure_pending_confirmation'];
        $ids = CleaningEquipmentReservation::query()->where('cleaning_booking_session_id', $visit->id)
            ->whereIn('status', $statuses)->pluck('cleaning_special_service_equipment_id')->unique()->sort()->values();
        $assets = CleaningSpecialServiceEquipment::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $rows = CleaningEquipmentReservation::query()->whereIn('cleaning_special_service_equipment_id', $ids)
            ->whereIn('status', $statuses)->orderBy('cleaning_special_service_equipment_id')->orderBy('id')->lockForUpdate()->get();
        $visitRows = $rows->where('cleaning_booking_session_id', $visit->id);
        $expected = array_sum(array_map(fn ($request) => (int) ($request['quantity'] ?? 0), $visit->pricing_snapshot['equipmentRequests'] ?? []));
        if ($expected > 0 && $visitRows->count() !== $expected) {
            throw new DomainException('V3_EXTENSION_EQUIPMENT_UNAVAILABLE');
        }
        foreach ($visitRows as $row) {
            $asset = $assets->get($row->cleaning_special_service_equipment_id);
            if ($asset === null || ! $asset->is_active || ! in_array($row->status, ['reserved', 'handed_over', 'acknowledged'], true)
                || in_array($asset->status, ['lost', 'maintenance', 'unavailable'], true)
                || $row->reserved_from === null || $row->reserved_until === null) {
                throw new DomainException('V3_EXTENSION_EQUIPMENT_UNAVAILABLE');
            }
            $newEnd = $until->addMinutes(max(0, (int) $asset->buffer_after_minutes));
            foreach ($rows->where('cleaning_special_service_equipment_id', $asset->id) as $other) {
                if ((int) $other->id !== (int) $row->id && ($other->status !== 'reserved'
                    || $other->reserved_from === null || $other->reserved_until === null || ($other->reserved_from < $newEnd && $other->reserved_until > $row->reserved_from))) {
                    throw new DomainException('V3_EXTENSION_EQUIPMENT_CONFLICT');
                }
            }
            if ($newEnd > $row->reserved_until) {
                $row->forceFill(['reserved_until' => $newEnd])->save();
            }
        }
    }
}
