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
        $payload['materialKit'] = $this->materialKitPayload();
        $payload['specialServices'] = $specialServices;
        $payload['specialServicesTotal'] = round(array_sum(array_column($specialServices, 'totalPrice')), 2);
        $payload['openTime'] = $openTime;
        $payload['scheduleChangeRequest'] = $this->scheduleChangeRequestPayload();
        $payload['schemaVersion'] = max(1, (int) ($this->capability_schema_version ?? 1));
        $payload['capabilities'] = [
            'materialKits' => true,
            'specialServiceItems' => true,
            'dynamicEventTypes' => true,
            'openTimeExtensions' => true,
            'scheduleChangeApprovals' => true,
        ];
        $payload['eventTypeId'] = $this->cleaning_event_type_id !== null ? (int) $this->cleaning_event_type_id : null;
        $payload['eventDynamicAnswers'] = $this->event_dynamic_answers ?? [];

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    private function materialsPayload(): array
    {
        $lines = $this->relationLoaded('materials') ? $this->materials : $this->materials()->with(['material', 'materialType', 'unit'])->get();

        return $lines->groupBy('cleaning_material_type_id')->map(static function ($allocations): array {
            /** @var CleaningBookingMaterial $line */
            $line = $allocations->first();

            return [
                'materialTypeId' => (int) $line->cleaning_material_type_id,
                'name' => (string) ($line->materialType?->name ?? ''),
                'quantity' => round((float) $allocations->sum('quantity'), 3),
                'unitId' => (int) $line->cleaning_material_unit_id,
                'unit' => $line->unit?->name,
                'unitCode' => $line->unit?->code,
                'unitSymbol' => $line->unit?->symbol,
                'unitPrice' => (float) $line->unit_price,
                'totalPrice' => round((float) $allocations->sum('total_price'), 2),
                'inventoryStatus' => $allocations->contains(fn ($item) => $item->inventory_status === 'reserved') ? 'reserved' : (string) $line->inventory_status,
            ];
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function specialServicesPayload(): array
    {
        $lines = $this->relationLoaded('specialServices') ? $this->specialServices : $this->specialServices()->with('specialService')->get();

        return $lines->map(static fn (CleaningBookingSpecialService $line): array => [
            'specialServiceId' => (int) $line->cleaning_special_service_id,
            'name' => (string) $line->service_name,
            'image' => $line->specialService?->imageUrl(),
            'pricingUnit' => (string) $line->pricing_unit,
            'dirtinessLevel' => (string) $line->dirtiness_level,
            'quantity' => (float) $line->quantity,
            'baseUnitPrice' => (float) $line->base_unit_price,
            'priceMultiplier' => (float) $line->price_multiplier,
            'totalPrice' => (float) $line->total_price,
            'equipment' => is_array($line->equipment_snapshot) ? $line->equipment_snapshot : [],
            'notes' => $line->notes,
            'sessionIds' => $line->sessions()->pluck('cleaning_booking_sessions.id')->map(fn ($id) => (int) $id)->values()->all(),
            'executionStatus' => (string) $line->execution_status,
            'unableReason' => $line->unable_reason,
            'assignedWorkerId' => $line->assigned_worker_id !== null ? (int) $line->assigned_worker_id : null,
            'items' => $line->items()->get()->map(static fn ($item): array => [
                'id' => (int) $item->id,
                'dirtinessLevelId' => $item->cleaning_dirtiness_level_id !== null ? (int) $item->cleaning_dirtiness_level_id : null,
                'dirtinessLevel' => $item->dirtiness_level,
                'quantity' => (float) $item->quantity,
                'priceMultiplier' => (float) $item->price_multiplier,
                'baseUnitPrice' => (float) $item->base_unit_price,
                'totalPrice' => (float) $item->total_price,
                'notes' => $item->notes,
                'beforeImages' => $item->before_images ?? [],
                'afterImages' => $item->after_images ?? [],
            ])->values()->all(),
        ])->values()->all();
    }

    private function materialKitPayload(): ?array
    {
        $kit = $this->materialKit()->first();
        if ($kit === null) {
            return null;
        }

        return [
            'status' => (string) $kit->status,
            'preparedAt' => $kit->prepared_at?->toIso8601String(),
            'receivedAt' => $kit->received_at?->toIso8601String(),
            'receivedByWorkerId' => $kit->received_by_worker_id !== null ? (int) $kit->received_by_worker_id : null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function scheduleChangeRequestPayload(): ?array
    {
        $change = $this->scheduleChangeRequests()
            ->whereIn('status', ['pending', 'rejected'])
            ->with('decisions.worker.user')
            ->latest('id')
            ->first();
        if ($change === null) {
            return null;
        }

        return [
            'id' => (int) $change->id,
            'bookingId' => (int) $change->cleaning_booking_id,
            'status' => (string) $change->status,
            'changeType' => (string) $change->change_type,
            'affectedSessionIds' => array_map('intval', (array) $change->affected_session_ids),
            'beforeSnapshot' => (array) $change->before_snapshot,
            'proposedSnapshot' => (array) $change->proposed_snapshot,
            'priceDelta' => round((float) $change->price_delta, 2),
            'customerResolution' => $change->customer_resolution,
            'createdAt' => $change->created_at?->toIso8601String(),
            'decisions' => $change->decisions->map(static fn ($decision): array => [
                'workerId' => (int) $decision->worker_id,
                'workerName' => $decision->worker?->user?->name ?? $decision->worker?->first_name,
                'decision' => (string) $decision->decision,
                'reason' => $decision->reason,
                'decidedAt' => $decision->decided_at?->toIso8601String(),
            ])->values()->all(),
        ];
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
