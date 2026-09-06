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
        $payload['specialServices'] = $this->specialServicesPayload();
        $payload['openTime'] = app(CleaningOpenTimeBillingService::class)->presentation($this->resource);

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    private function materialsPayload(): array
    {
        $lines = $this->relationLoaded('materials') ? $this->materials : $this->materials()->with(['material', 'unit'])->get();

        return $lines->map(static fn (CleaningBookingMaterial $line): array => [
            'id' => (int) $line->cleaning_material_id,
            'materialId' => (int) $line->cleaning_material_id,
            'name' => (string) ($line->material?->name ?? ''),
            'quantity' => (float) $line->quantity,
            'unit' => $line->unit?->code,
            'unitLabel' => $line->unit?->name,
            'inventoryStatus' => (string) $line->inventory_status,
        ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function specialServicesPayload(): array
    {
        $lines = $this->relationLoaded('specialServices') ? $this->specialServices : $this->specialServices()->get();

        return $lines->map(static fn (CleaningBookingSpecialService $line): array => [
            'id' => (int) $line->cleaning_special_service_id,
            'serviceId' => (int) $line->cleaning_special_service_id,
            'name' => (string) $line->service_name,
            'quantity' => (float) $line->quantity,
            'pricingUnit' => (string) $line->pricing_unit,
            'pricingUnitLabel' => str_replace('_', ' ', (string) $line->pricing_unit),
            'dirtinessLevel' => (string) $line->dirtiness_level,
            'dirtinessLabel' => str_replace('_', ' ', (string) $line->dirtiness_level),
            'equipment' => is_array($line->equipment_snapshot) ? $line->equipment_snapshot : [],
            'notes' => $line->notes,
        ])->values()->all();
    }
}
