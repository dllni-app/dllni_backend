<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingMaterial;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Services\CleaningOpenTimeBillingService;

/** @mixin CleaningBooking */
final class CleaningBookingOperationalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payload = (new CleaningBookingResource($this->resource))->toArray($request);

        $payload['bookingKind'] = (string) ($this->booking_kind ?? 'standard');
        $payload['materials'] = $this->materialsPayload();
        $payload['materialKit'] = $this->materialKitPayload();
        $payload['specialServices'] = $this->specialServicesPayload();
        $payload['openTime'] = app(CleaningOpenTimeBillingService::class)->presentation($this->resource);
        // Operational clients use this envelope to gate additive v2 fields
        // while still reading the legacy booking payload above.
        $payload['schemaVersion'] = 2;
        $payload['serverNow'] = now()->toIso8601String();
        $payload['capabilities'] = [
            'materialKits' => true,
            'specialServiceItems' => true,
            'dynamicEventTypes' => true,
            'openTimeExtensions' => true,
            'scheduleChangeApprovals' => true,
        ];

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    private function materialsPayload(): array
    {
        $lines = $this->relationLoaded('materials')
            ? $this->materials
            : $this->materials()->with(['material', 'materialType', 'unit'])->get();

        return $lines->groupBy('cleaning_material_type_id')->map(static function ($allocations): array {
            /** @var CleaningBookingMaterial $line */
            $line = $allocations->first();

            return [
                'materialTypeId' => (int) $line->cleaning_material_type_id,
                'name' => (string) ($line->materialType?->name ?? ''),
                'quantity' => round((float) $allocations->sum('quantity'), 3),
                'unit' => $line->unit?->code,
                'unitLabel' => $line->unit?->name,
                'inventoryStatus' => $allocations->contains(fn ($item) => $item->inventory_status === 'reserved') ? 'reserved' : (string) $line->inventory_status,
            ];
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function specialServicesPayload(): array
    {
        $lines = $this->relationLoaded('specialServices') ? $this->specialServices : $this->specialServices()->get();

        return $lines->map(static fn (CleaningBookingSpecialService $line): array => [
            'id' => (int) $line->id,
            'serviceId' => (int) $line->cleaning_special_service_id,
            'name' => (string) $line->service_name,
            'quantity' => (float) $line->quantity,
            'pricingUnit' => (string) $line->pricing_unit,
            'pricingUnitLabel' => str_replace('_', ' ', (string) $line->pricing_unit),
            'dirtinessLevel' => (string) $line->dirtiness_level,
            'dirtinessLabel' => str_replace('_', ' ', (string) $line->dirtiness_level),
            'equipment' => is_array($line->equipment_snapshot) ? $line->equipment_snapshot : [],
            'notes' => $line->notes,
            'executionStatus' => (string) $line->execution_status,
            'unableReason' => $line->unable_reason,
            'assignedWorkerId' => $line->assigned_worker_id !== null ? (int) $line->assigned_worker_id : null,
            'sessionIds' => $line->sessions()->pluck('cleaning_booking_sessions.id')->map(fn ($id) => (int) $id)->values()->all(),
            'items' => $line->items()->get()->map(static fn ($item): array => [
                'id' => (int) $item->id,
                'quantity' => (float) $item->quantity,
                'dirtinessLevel' => $item->dirtiness_level,
                'notes' => $item->notes,
                'beforeImages' => $item->before_images ?? [],
                'afterImages' => $item->after_images ?? [],
            ])->values()->all(),
            'equipmentReservations' => $line->equipmentReservations()->with('equipment')->get()->map(static fn ($reservation): array => [
                'id' => (int) $reservation->id,
                'equipmentId' => (int) $reservation->cleaning_special_service_equipment_id,
                'name' => (string) ($reservation->equipment?->name ?? ''),
                'assetCode' => $reservation->equipment?->asset_code,
                'status' => (string) $reservation->status,
                'reservedFrom' => $reservation->reserved_from?->toIso8601String(),
                'reservedUntil' => $reservation->reserved_until?->toIso8601String(),
                'acknowledgedAt' => $reservation->acknowledged_at?->toIso8601String(),
                'returnedAt' => $reservation->returned_at?->toIso8601String(),
                'failureReason' => $reservation->failure_reason,
            ])->values()->all(),
        ])->values()->all();
    }

    private function materialKitPayload(): ?array
    {
        $kit = $this->materialKit()->first();

        return $kit === null ? null : [
            'status' => (string) $kit->status,
            'preparedAt' => $kit->prepared_at?->toIso8601String(),
            'receivedAt' => $kit->received_at?->toIso8601String(),
            'receivedByWorkerId' => $kit->received_by_worker_id !== null ? (int) $kit->received_by_worker_id : null,
        ];
    }
}
