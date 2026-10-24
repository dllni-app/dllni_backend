<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingMaterial;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Services\CleaningOpenTimeBillingService;
use Modules\Cleaning\Services\CleaningPricingCalculator;
use Modules\Cleaning\Services\CleaningSpecialServiceDurationPlanningService;
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
        $legacyMinimumPriceCompatibility = (string) ($this->booking_kind ?? 'standard') === 'standard'
            && app(CleaningPricingCalculator::class)->minimumOrderAdminMarginBase(
                $bookingBasePrice,
                (string) $this->property_type,
                (float) ($this->addons_total ?? 0),
                (float) ($this->travel_fee ?? 0),
                (float) ($this->total_price ?? 0),
            ) > 0.0;

        // Temporary compatibility for the currently published Flutter app.
        // That app historically expects basePrice to already be the displayed
        // service value on order details. For a commission-inclusive minimum,
        // use the authoritative customer total minus travel and expose the real
        // margin separately so backend accounting remains unchanged.
        if ($discountAmount <= 0.0 && $extensionFeeTotal <= 0.0) {
            if ($legacyMinimumPriceCompatibility) {
                $displayServicePrice = round(max(
                    0.0,
                    (float) ($payload['totalPrice'] ?? 0) - (float) ($payload['travelFee'] ?? 0),
                ), 2);
                $payload['actualAdminMargin'] = $bookingAdminMargin;
                $payload['adminMargin'] = 0.0;
                $payload['legacyMinimumPriceCompatibility'] = true;
            } else {
                $displayServicePrice = round($bookingBasePrice + $bookingAdminMargin, 2);
            }

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
        $openTime = (string) ($this->booking_kind ?? 'standard') === 'open_time'
            ? app(CleaningOpenTimeBillingService::class)->presentation($this->resource)
            : null;

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
        $payload['lastHourTeamDecision'] = app(
            \Modules\Cleaning\Services\CleaningLastHourCoverageService::class
        )->decision($this->resource);
        $payload['requestMaterials'] = $materials !== [];
        $payload['materials'] = $materials;
        $payload['materialsTotal'] = round(array_sum(array_column($materials, 'totalPrice')), 2);
        $payload['materialKit'] = $this->materialKitPayload();
        $payload['specialServices'] = $specialServices;
        $payload['specialServicesTotal'] = round(array_sum(array_column($specialServices, 'totalPrice')), 2);
        $payload['specialServiceDurationPlan'] = $specialServices !== []
            ? app(CleaningSpecialServiceDurationPlanningService::class)->forBooking($this->resource)
            : null;
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

        // Room-period V2 has separate room-specific dates, workers and extras.
        // Never flatten it into legacy booking-level fields: that would lose
        // the selections the customer approved. Only the overall amount is
        // public; transportation/material/commission lines stay internal.
        $periods = $this->roomPeriods()
            ->orderBy('visit_sequence')
            ->orderBy('client_key')
            ->get();
        if ($periods->isNotEmpty()) {
            $payload['roomPeriods'] = $periods->map(static fn ($period): array => [
                'clientKey' => (string) $period->client_key,
                'visitSequence' => (int) $period->visit_sequence,
                'roomType' => (string) $period->room_type,
                'size' => (string) $period->room_size,
                'cleaningMode' => (string) $period->cleaning_mode,
                'scheduledDate' => $period->scheduled_date?->format('Y-m-d'),
                'scheduledTime' => mb_substr((string) $period->scheduled_time, 0, 5),
                'numberOfWorkers' => (int) $period->required_workers,
                'estimatedDurationMinutes' => (int) $period->estimated_duration_minutes,
                'materials' => (array) $period->materials,
                'specialServices' => (array) $period->special_services,
                'notes' => $period->notes,
            ])->values()->all();
            $payload['schemaVersion'] = max(2, (int) $payload['schemaVersion']);
            $payload['capabilities']['roomPeriods'] = true;
            $payload['travelFeePending'] = false;
        }

        // V2 is reconstructed from committed room/schedule/period/material/service rows.
        // The approved quote snapshot is only retained for read-only requirements
        // that have no separate inventory row when the customer brings materials.
        $timePeriodSnapshot = $this->property_details['_time_period_v2'] ?? null;
        if (is_array($timePeriodSnapshot)) {
            $details = app(\Modules\User\Services\CleaningTimePeriodCustomerDetailService::class)
                ->forBooking($this->resource);
            if ($details !== null) {
                $payload['contractVersion'] = 2;
                $payload['rooms'] = $details['rooms'];
                $payload['visits'] = $details['visits'];
                $payload['recurrence'] = $details['recurrence'];
                $payload['workforce'] = $details['workforce'];
                $payload['pricing'] = $details['pricing'];
                $payload['totalPrice'] = $details['pricing']['totalPrice'];
                // The internal quote display snapshot is never a public detail field.
                $publicDetails = (array) ($payload['propertyDetails'] ?? []);
                foreach (array_keys($publicDetails) as $field) {
                    if (str_starts_with((string) $field, '_')) {
                        unset($publicDetails[$field]);
                    }
                }
                $payload['propertyDetails'] = $publicDetails;
                $payload['property_details'] = $publicDetails;
                $payload['approvedMaterialsPreview'] = $timePeriodSnapshot['materialsPreview'] ?? [];
                $payload['materials'] = collect($details['visits'])
                    ->flatMap(static fn (array $visit): array => $visit['timePeriods'])
                    ->flatMap(static fn (array $period): array => $period['platformMaterials'])
                    ->values()->all();
                $payload['specialServices'] = collect($details['visits'])
                    ->flatMap(static fn (array $visit): array => $visit['timePeriods'])
                    ->flatMap(static fn (array $period): array => $period['specialServices'])
                    ->values()->all();
                $payload['travelFeePending'] = false;
                $payload['capabilities']['timePeriods'] = true;
                $payload['canEdit'] = false;
                $payload['can_edit'] = false;
                // Private operational accounting must not be exposed to V2 customers.
                unset($payload['basePrice'], $payload['servicePrice'], $payload['service_price'],
                    $payload['adminMargin'], $payload['actualAdminMargin'], $payload['travelFee'],
                    $payload['addonsTotal'], $payload['materialsTotal'], $payload['specialServicesTotal'],
                    $payload['subtotalBeforeDiscount'], $payload['legacyMinimumPriceCompatibility'],
                    $payload['bookingBasePrice'], $payload['bookingAddonsTotal'],
                    $payload['bookingTravelFee'], $payload['bookingAdminMargin'],
                    $payload['workerAmount'], $payload['serviceShareAmount'],
                    $payload['deliveryFee'], $payload['travelDistanceKm'],
                    $payload['extensionFeeTotal'], $payload['discountAmount'],
                    $payload['materialKit'], $payload['specialServiceDurationPlan'],
                    $payload['services'], $payload['cleaning_services']);
            }
        }

        return $payload;
    }

    /** @param array<int, mixed> $paths */
    private static function publicImageUrls(array $paths): array
    {
        return collect($paths)->filter(static fn ($value): bool => is_string($value) && mb_trim($value) !== '')
            ->map(static function (string $path): ?string {
                $path = mb_trim($path);
                if (preg_match('/^https?:\/\//i', $path)) {
                    return $path;
                }
                if (str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path)) {
                    return null;
                }
                $uri = Storage::disk('public')->url($path);

                return str_starts_with($uri, 'http') ? $uri : url($uri);
            })
            ->filter()
            ->values()
            ->all();
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
                'beforeImageUrls' => self::publicImageUrls((array) ($item->before_images ?? [])),
                'afterImageUrls' => self::publicImageUrls((array) ($item->after_images ?? [])),
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
            CleaningBookingStatus::PartiallyCompleted->value,
        ], true);
    }
}
