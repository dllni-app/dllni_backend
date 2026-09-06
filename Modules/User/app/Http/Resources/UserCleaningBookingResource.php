<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingMaterial;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Services\CleaningOpenTimeBillingService;
use Modules\User\Services\UserCleaningOrderEstimationService;

/** @mixin CleaningBooking */
final class UserCleaningBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payload = (new CleaningBookingResource($this->resource))->toArray($request);

        $discountAmount = max(0.0, (float) ($this->discount_amount ?? 0));
        $extensionFeeTotal = max(0.0, (float) ($this->extension_fee_total ?? 0));
        $bookingBasePrice = max(0.0, (float) ($this->base_price ?? 0));
        $bookingAdminMargin = max(0.0, (float) ($this->admin_margin_amount ?? 0));

        if ($discountAmount <= 0.0 && $extensionFeeTotal <= 0.0) {
            $displayServicePrice = round($bookingBasePrice + $bookingAdminMargin, 2);
            $payload['basePrice'] = $displayServicePrice;
            $payload['servicePrice'] = $displayServicePrice;
            $payload['service_price'] = $displayServicePrice;
        }

        $workerCount = max(1, (int) ($this->number_of_workers ?? 1));
        $bookingEstimatedHours = $this->estimated_hours !== null
            ? (float) $this->estimated_hours
            : null;
        $bookingTotalHours = (float) ($this->total_hours ?? $bookingEstimatedHours ?? 0);
        $isEventAssistance = (string) $this->property_type === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE;

        if (! $isEventAssistance && $workerCount > 1) {
            if ($bookingEstimatedHours !== null) {
                $payload['estimatedHours'] = round($bookingEstimatedHours / $workerCount, 2);
            }
            $payload['totalHours'] = round($bookingTotalHours / $workerCount, 2);
        }

        $canEdit = $this->canEdit();
        $materials = $this->materialsPayload();
        $specialServices = $this->specialServicesPayload();
        $openTime = app(CleaningOpenTimeBillingService::class)->presentation($this->resource);

        $payload['bookingEstimatedHours'] = $bookingEstimatedHours;
        $payload['bookingTotalHours'] = $bookingTotalHours;
        $payload['durationWorkerCount'] = $workerCount;
        $payload['discountAmount'] = $discountAmount;
        $payload['subtotalBeforeDiscount'] = (float) ($this->subtotal_before_discount ?? 0);
        $payload['travelFeePending'] = ! (bool) $this->is_pricing_final;
        $payload['canEdit'] = $canEdit;
        $payload['can_edit'] = $canEdit;
        $payload['workerScope'] = $this->resolvedWorkerScope();
        $payload['specificWorkerIds'] = $this->resolvedWorkerScope() === CleaningBooking::WORKER_SCOPE_SPECIFIC
            ? $this->specificWorkerIds()
            : [];
        $payload['bookingKind'] = (string) ($this->booking_kind ?? 'standard');
        $payload['requestMaterials'] = $materials !== [];
        $payload['materials'] = $materials;
        $payload['materialsTotal'] = round(array_sum(array_column($materials, 'totalPrice')), 2);
        $payload['specialServices'] = $specialServices;
        $payload['specialServicesTotal'] = round(array_sum(array_column($specialServices, 'totalPrice')), 2);
        $payload['openTime'] = $openTime;

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    private function materialsPayload(): array
    {
        $lines = $this->relationLoaded('materials') ? $this->materials : $this->materials()->with(['material', 'materialType', 'unit'])->get();

        return $lines->map(static fn (CleaningBookingMaterial $line): array => [
            'materialId' => (int) $line->cleaning_material_id,
            'name' => (string) ($line->material?->name ?? $line->materialType?->name ?? ''),
            'materialTypeId' => (int) $line->cleaning_material_type_id,
            'quantity' => (float) $line->quantity,
            'unitId' => (int) $line->cleaning_material_unit_id,
            'unit' => $line->unit?->name,
            'unitCode' => $line->unit?->code,
            'unitSymbol' => $line->unit?->symbol,
            'unitPrice' => (float) $line->unit_price,
            'totalPrice' => (float) $line->total_price,
            'inventoryStatus' => (string) $line->inventory_status,
        ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function specialServicesPayload(): array
    {
        $lines = $this->relationLoaded('specialServices') ? $this->specialServices : $this->specialServices()->with('specialService')->get();

        return $lines->map(static fn (CleaningBookingSpecialService $line): array => [
            'specialServiceId' => (int) $line->cleaning_special_service_id,
            'name' => (string) $line->service_name,
            'image' => $line->specialService?->image_url,
            'pricingUnit' => (string) $line->pricing_unit,
            'dirtinessLevel' => (string) $line->dirtiness_level,
            'quantity' => (float) $line->quantity,
            'baseUnitPrice' => (float) $line->base_unit_price,
            'priceMultiplier' => (float) $line->price_multiplier,
            'totalPrice' => (float) $line->total_price,
            'equipment' => is_array($line->equipment_snapshot) ? $line->equipment_snapshot : [],
            'notes' => $line->notes,
        ])->values()->all();
    }

    private function canEdit(): bool
    {
        $status = $this->status instanceof CleaningBookingStatus
            ? $this->status->value
            : (string) $this->status;

        return ! in_array($status, [
            CleaningBookingStatus::InProgress->value,
            CleaningBookingStatus::Completed->value,
            CleaningBookingStatus::Cancelled->value,
        ], true);
    }
}
