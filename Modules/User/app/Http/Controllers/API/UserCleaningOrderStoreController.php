<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\PlatformCoupon;
use App\Services\Coupons\PlatformCouponRedemptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Services\CleaningCouponPricingService;
use Modules\User\Http\Requests\UserCleaningOrderStoreRequest;
use Modules\User\Http\Resources\UserCleaningBookingResource;
use Modules\User\Services\EventAssistanceScheduleService;
use Modules\User\Services\OpenTimeScheduleService;
use Modules\User\Services\RecurringCleaningScheduleService;
use Modules\User\Services\UserCleaningOrderEstimationService;
use Modules\User\Services\UserCleaningOrderService;
use Modules\User\Services\RoomPeriodBookingCreationService;
use Modules\User\Support\CleaningWorkerCapacity;

final class UserCleaningOrderStoreController
{
    public function __invoke(
        UserCleaningOrderStoreRequest $request,
        UserCleaningOrderService $service,
        UserCleaningOrderEstimationService $estimationService,
        EventAssistanceScheduleService $eventSchedule,
        RecurringCleaningScheduleService $recurringSchedule,
        OpenTimeScheduleService $openTimeSchedule,
        PlatformCouponRedemptionService $platformCoupons,
        CleaningCouponPricingService $couponPricing,
        RoomPeriodBookingCreationService $periodCreation,
    ): JsonResponse {
        $couponCode = $request->input('couponCode');
        Validator::make(['couponCode' => $couponCode], [
            'couponCode' => ['nullable', 'string', 'max:50'],
        ])->validate();
        $validated = $this->normalizeWorkerCount($request->validated());
        if (($validated['serviceType'] ?? null) === 'supervised_team' && ! config('cleaning_supervisor.booking_enabled')) {
            return response()->json([
                'code' => 'FEATURE_NOT_CONFIGURED',
                'message' => 'حجز فريق تحت الإشراف غير متاح حتى اعتماد آلية التحقق والموافقات.',
            ], 409);
        }
        if (($validated['serviceType'] ?? null) === 'supervised_team') {
            if ((int) ($validated['numberOfWorkers'] ?? 0) < 2
                || (int) ($validated['contractVersion'] ?? 0) === 2
                || ! empty($validated['openTime']) || ! empty($validated['roomPeriods'])
                || ! empty($validated['recurringSchedule']) || ! empty($validated['workerRoomAssignments'])
                || ! empty($validated['preferredWorkerIds']) || ! empty($validated['preferredWorkerId'])
                || ($validated['assignmentMode'] ?? 'open_count') !== 'open_count'
                || $estimationService->isEventAssistanceType((string) $validated['propertyType'])) {
                throw ValidationException::withMessages(['serviceType' => ['يجب اختيار عاملين على الأقل وخدمة فريق بموعد واحد.']]);
            }
        }
        if ((int) ($validated['contractVersion'] ?? 0) === 2) {
            $booking = app(\Modules\User\Services\CleaningTimePeriodBookingService::class)->create($request->user(), $validated);
            $details = app(\Modules\User\Services\CleaningTimePeriodCustomerDetailService::class)->forBooking($booking);
            if ($details === null) {
                throw new \LogicException('Persisted V2 booking schedules are missing.');
            }

            return response()->json(['order' => UserCleaningBookingResource::make($booking), 'contractVersion' => 2, ...$details], 201);
        }
        if (is_array($validated['roomPeriods'] ?? null)) {
            if ($request->filled('couponCode')) {
                throw ValidationException::withMessages([
                    'couponCode' => ['Period-level coupon support is not available yet; no booking was created.'],
                ]);
            }

            $booking = $periodCreation->create(
                $request->user(),
                $validated,
                (string) $validated['quoteSignature'],
            );
            $booking->load([
                'worker.user', 'preferredWorker.user',
                'rooms.assignedWorker.user', 'workerAssignments.worker.user',
                'timeWarnings', 'disputes', 'addons', 'billingPolicy',
            ]);

            return response()->json([
                'order' => UserCleaningBookingResource::make($booking),
                'roomPeriods' => $booking->roomPeriods()
                    ->get()
                    ->map(static fn ($period): array => [
                        'clientKey' => $period->client_key,
                        'visitSequence' => $period->visit_sequence,
                        'roomType' => $period->room_type,
                        'size' => $period->room_size,
                        'cleaningMode' => $period->cleaning_mode,
                        'scheduledDate' => $period->scheduled_date?->format('Y-m-d'),
                        'scheduledTime' => substr((string) $period->scheduled_time, 0, 5),
                        'numberOfWorkers' => $period->required_workers,
                        'estimatedDurationMinutes' => $period->estimated_duration_minutes,
                        'materials' => $period->materials,
                        'specialServices' => $period->special_services,
                    ])->values()->all(),
            ], 201);
        }
        $validated = $this->withEventWorkerCount($validated);
        $this->assertWorkerCapacity($validated, $estimationService);
        $isEventAssistance = $estimationService->isEventAssistanceType((string) ($validated['propertyType'] ?? ''));
        $eventPlan = $isEventAssistance
            ? $eventSchedule->resolve($validated)
            : null;
        $openTimePlan = ! $isEventAssistance
            ? $openTimeSchedule->resolve($validated)
            : null;
        $recurringPlan = ! $isEventAssistance && $openTimePlan === null
            ? $recurringSchedule->resolve($validated)
            : null;

        $order = DB::transaction(function () use (
            $request,
            $service,
            $estimationService,
            $eventSchedule,
            $eventPlan,
            $recurringSchedule,
            $recurringPlan,
            $openTimeSchedule,
            $openTimePlan,
            $platformCoupons,
            $couponPricing,
            $couponCode,
            $validated,
        ) {
            $order = $service->store($request->user(), $validated);

            if ($eventPlan !== null) {
                $eventPricing = $eventSchedule->quote(
                    plan: $eventPlan,
                    propertyType: (string) $order->property_type,
                    propertyDetails: (array) ($order->property_details ?? []),
                    addressLatitude: $order->address_latitude,
                    addressLongitude: $order->address_longitude,
                    preferredWorkerId: $order->resolvedAssignmentMode() === 'preferred_worker'
                        ? $order->preferred_worker_id
                        : null,
                    requiredWorkers: max(1, (int) $order->number_of_workers),
                    requestMaterials: (bool) ($validated['requestMaterials'] ?? false),
                    specialServices: is_array($validated['specialServices'] ?? null) ? $validated['specialServices'] : [],
                );

                $order->forceFill([
                    'property_details' => $eventSchedule->withAggregateHours(
                        (array) ($order->property_details ?? []),
                        $eventPlan,
                    ),
                    'estimated_hours' => $eventPlan['totalHours'],
                    'total_hours' => $eventPlan['totalHours'],
                    'scheduled_date' => $eventPlan['firstDate'],
                    'scheduled_time' => $eventPlan['firstTime'],
                    'base_price' => $eventPricing['basePrice'],
                    'addons_total' => $eventPricing['addonsTotal'],
                    'travel_fee' => $eventPricing['travelFee'],
                    'travel_distance_km' => $eventPricing['distanceKm'],
                    'admin_margin_amount' => $eventPricing['adminMargin'],
                    'is_pricing_final' => $eventPricing['isPricingFinal'],
                    'total_price' => $eventPricing['totalPrice'],
                ])->save();

                $eventSchedule->createSessions($order->fresh(), $eventPlan, $eventPricing);
                $order = $order->fresh();
            } elseif ($openTimePlan !== null) {
                $openTimePricing = $openTimeSchedule->quote($openTimePlan, [
                    'basePrice' => (float) $order->base_price,
                    'addonsTotal' => 0.0,
                    'travelFee' => (float) $order->travel_fee,
                    'distanceKm' => $order->travel_distance_km,
                    'adminMargin' => (float) $order->admin_margin_amount,
                    'isPricingFinal' => (bool) $order->is_pricing_final,
                    'totalPrice' => (float) $order->total_price,
                    'currency' => (string) config('app.currency', 'SYP'),
                    'openTime' => [
                        'hourlyRate' => (float) $order->open_time_hourly_rate,
                        'requestedWorkerCount' => max(1, (int) $order->number_of_workers),
                        'minimumBillableMinutes' => (int) $order->open_time_minimum_minutes,
                        'roundingMinutes' => (int) $order->open_time_rounding_minutes,
                        'expectedMaxMinutes' => (int) $order->open_time_expected_max_minutes,
                        'hardMaxMinutes' => (int) $order->open_time_hard_max_minutes,
                        'warningMinutes' => (int) $order->open_time_warning_minutes,
                        'extensionOptions' => (array) $order->open_time_extension_options,
                    ],
                ]);
                $order = $openTimeSchedule->materialize($order, $openTimePlan, $openTimePricing);
            } elseif ($recurringPlan !== null) {
                $sessionHours = (string) ($recurringPlan['calculationMode'] ?? RecurringCleaningScheduleService::CALCULATION_TASK) === RecurringCleaningScheduleService::CALCULATION_HOURS
                    ? (float) ($recurringPlan['hoursPerVisit'] ?? $order->estimated_hours)
                    : (float) $order->estimated_hours;
                $singleVisitPricing = null;
                if ((string) ($recurringPlan['calculationMode'] ?? RecurringCleaningScheduleService::CALCULATION_TASK) === RecurringCleaningScheduleService::CALCULATION_HOURS) {
                    $hourPricing = $estimationService->priceRecurringHours(
                        (string) $order->property_type,
                        (array) ($order->property_details ?? []),
                        $order->address_latitude,
                        $order->address_longitude,
                        $order->resolvedAssignmentMode() === 'preferred_worker' ? $order->preferred_worker_id : null,
                        $sessionHours,
                        max(1, (int) $order->number_of_workers),
                        (bool) ($validated['requestMaterials'] ?? false),
                        is_array($validated['specialServices'] ?? null) ? $validated['specialServices'] : [],
                    );
                    $storedHourPricing = $order->resolvedAssignmentMode() === 'preferred_worker'
                        ? $hourPricing
                        : [
                            ...$hourPricing,
                            'travelFee' => 0.0,
                            'distanceKm' => null,
                            'adminMargin' => 0.0,
                            'isPricingFinal' => false,
                            'totalPrice' => round((float) $hourPricing['basePrice'] + (float) $hourPricing['addonsTotal'], 2),
                        ];
                    $singleVisitPricing = $storedHourPricing;
                    $order->forceFill([
                        'estimated_hours' => $sessionHours,
                        'total_hours' => $sessionHours,
                        'base_price' => $storedHourPricing['basePrice'],
                        'addons_total' => $storedHourPricing['addonsTotal'],
                        'travel_fee' => $storedHourPricing['travelFee'],
                        'travel_distance_km' => $storedHourPricing['distanceKm'],
                        'admin_margin_amount' => $storedHourPricing['adminMargin'],
                        'is_pricing_final' => $storedHourPricing['isPricingFinal'],
                        'total_price' => $storedHourPricing['totalPrice'],
                    ])->save();
                    $order = $order->fresh();
                } else {
                    $singleVisitPricing = $estimationService->price(
                        (string) $order->property_type,
                        (array) ($order->property_details ?? []),
                        $order->address_latitude,
                        $order->address_longitude,
                        $order->resolvedAssignmentMode() === 'preferred_worker' ? $order->preferred_worker_id : null,
                        null,
                        (bool) ($validated['requestMaterials'] ?? false),
                        is_array($validated['specialServices'] ?? null) ? $validated['specialServices'] : [],
                        max(1, (int) $order->number_of_workers),
                    );
                }

                $recurringPricing = $recurringSchedule->quote(
                    $recurringPlan,
                    (array) $singleVisitPricing,
                    $sessionHours,
                );
                $order = $recurringSchedule->materialize($order, $recurringPlan, $recurringPricing);
            }

            if (is_array($validated['specialServices'] ?? null) && $validated['specialServices'] !== []) {
                $service->syncSpecialServiceSessions($order, $validated['specialServices']);
            }

            if (is_string($couponCode) && mb_trim($couponCode) !== '') {
                // The saved price is already the authoritative customer quote:
                // minimum-order pricing can include platform commission.
                $subtotal = round(max(0.0, (float) $order->total_price), 2);
                $serviceSubtotal = round(max(0.0, (float) $order->base_price)
                    + max(0.0, (float) $order->addons_total), 2);
                $travelFee = round(max(0.0, (float) $order->travel_fee), 2);
                $grossAdminMargin = round(max(0.0, (float) $order->admin_margin_amount), 2);
                $includedAdminMargin = round(min($grossAdminMargin, max(
                    0.0,
                    $serviceSubtotal + $travelFee + $grossAdminMargin - $subtotal,
                )), 2);
                $quote = $platformCoupons->quoteForPlacement(
                    userId: (int) $request->user()->id,
                    section: PlatformCoupon::SECTION_CLEANING,
                    couponCode: $couponCode,
                    subtotal: $subtotal,
                    context: [
                        'propertyType' => (string) $order->property_type,
                        'cleaningMode' => $order->property_details['cleaning_mode'] ?? null,
                        'eventType' => $order->property_details['event_type'] ?? $order->property_details['eventType'] ?? null,
                    ],
                    required: true,
                );

                // Coupon eligibility uses the customer subtotal. The discount
                // itself excludes transport and consumes platform margin first.
                $allocation = $couponPricing->allocation(
                    $quote['coupon'],
                    $serviceSubtotal,
                    $travelFee,
                    $grossAdminMargin,
                    $includedAdminMargin,
                );
                $discount = (float) $allocation['discountAmount'];
                $order->forceFill([
                    'subtotal_before_discount' => $subtotal,
                    'discount_amount' => $discount,
                    'total_price' => round(max(0.0, $subtotal - $discount), 2),
                ])->save();

                $platformCoupons->record(
                    coupon: $quote['coupon'],
                    userId: (int) $request->user()->id,
                    section: PlatformCoupon::SECTION_CLEANING,
                    subtotal: $subtotal,
                    discount: $discount,
                    order: $order,
                );
            }

            return $order->fresh();
        });

        $order->load([
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'timeWarnings',
            'disputes',
            'addons',
            'billingPolicy',
        ]);

        return response()->json(['order' => UserCleaningBookingResource::make($order)], 201);
    }

    /** @param array<string, mixed> $validated */
    private function normalizeWorkerCount(array $validated): array
    {
        $preferredWorkerIds = is_array($validated['preferredWorkerIds'] ?? null)
            ? array_values(array_unique(array_filter(
                array_map('intval', $validated['preferredWorkerIds']),
                static fn (int $id): bool => $id > 0,
            )))
            : [];
        if (array_key_exists('numberOfWorkers', $validated) && is_numeric($validated['numberOfWorkers'])) {
            $validated['numberOfWorkers'] = max(
                1,
                (int) $validated['numberOfWorkers'],
                count($preferredWorkerIds),
            );
        } elseif ($preferredWorkerIds !== []) {
            $validated['numberOfWorkers'] = count($preferredWorkerIds);
        }

        return $validated;
    }

    /** @param array<string, mixed> $validated */
    private function assertWorkerCapacity(array $validated, UserCleaningOrderEstimationService $estimationService): void
    {
        $propertyType = (string) ($validated['propertyType'] ?? '');
        if ($estimationService->isEventAssistanceType($propertyType) || is_array($validated['openTime'] ?? null)) {
            return;
        }

        $estimation = $estimationService->estimate(
            $propertyType,
            (array) ($validated['propertyDetails'] ?? []),
        );
        $schedule = is_array($validated['schedule'] ?? null) ? $validated['schedule'] : [];
        $calculationMode = mb_strtolower(mb_trim((string) ($schedule['calculationMode'] ?? RecurringCleaningScheduleService::CALCULATION_TASK)));
        $capacityHours = mb_strtolower(mb_trim((string) ($schedule['mode'] ?? ''))) === 'recurring'
            && $calculationMode === RecurringCleaningScheduleService::CALCULATION_HOURS
                ? ceil(max(1.0, min(24.0, (float) ($schedule['hoursPerVisit'] ?? 0))) * 2) / 2
                : (float) $estimation['estimatedHours'];
        $requiredWorkers = CleaningWorkerCapacity::requiredWorkers($capacityHours);
        $requestedWorkers = max(1, (int) ($validated['numberOfWorkers'] ?? 1));

        if ($requestedWorkers >= $requiredWorkers) {
            return;
        }

        throw ValidationException::withMessages([
            'numberOfWorkers' => [
                "مدة العمل المقدرة تتجاوز 8 ساعات لكل عامل. يجب طلب {$requiredWorkers} عمال على الأقل لإتمام هذا الطلب.",
            ],
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function withEventWorkerCount(array $validated): array
    {
        if (mb_strtolower((string) ($validated['propertyType'] ?? '')) !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE) {
            return $validated;
        }

        $propertyDetails = (array) ($validated['propertyDetails'] ?? []);

        if (array_key_exists('numberOfWorkers', $validated) && is_numeric($validated['numberOfWorkers'])) {
            $propertyDetails['workerCount'] = max(1, (int) $validated['numberOfWorkers']);
        } else {
            $assignmentMode = mb_strtolower((string) ($validated['assignmentMode'] ?? ''));
            $preferredWorkerId = $validated['preferredWorkerId'] ?? null;

            if ($assignmentMode !== 'open_count' && is_numeric($preferredWorkerId) && (int) $preferredWorkerId > 0) {
                $propertyDetails['workerCount'] = 1;
            }
        }

        $validated['propertyDetails'] = $propertyDetails;

        return $validated;
    }
}
