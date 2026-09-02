<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\PlatformCoupon;
use App\Services\Coupons\PlatformCouponRedemptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Services\CleaningPricingCalculator;
use Modules\User\Http\Requests\UserCleaningOrderStoreRequest;
use Modules\User\Http\Resources\UserCleaningBookingResource;
use Modules\User\Services\EventAssistanceScheduleService;
use Modules\User\Services\UserCleaningOrderEstimationService;
use Modules\User\Services\UserCleaningOrderService;
use Modules\User\Support\CleaningWorkerCapacity;

final class UserCleaningOrderStoreController
{
    public function __invoke(
        UserCleaningOrderStoreRequest $request,
        UserCleaningOrderService $service,
        UserCleaningOrderEstimationService $estimationService,
        EventAssistanceScheduleService $eventSchedule,
        PlatformCouponRedemptionService $platformCoupons,
        CleaningPricingCalculator $pricingCalculator,
    ): JsonResponse {
        $couponCode = $request->input('couponCode');
        Validator::make(['couponCode' => $couponCode], [
            'couponCode' => ['nullable', 'string', 'max:50'],
        ])->validate();
        $validated = $this->normalizeWorkerCount($request->validated());
        $validated = $this->withEventWorkerCount($validated);
        $this->assertWorkerCapacity($validated, $estimationService);
        $eventPlan = $estimationService->isEventAssistanceType((string) ($validated['propertyType'] ?? ''))
            ? $eventSchedule->resolve($validated)
            : null;

        $order = DB::transaction(function () use ($request, $service, $eventSchedule, $eventPlan, $platformCoupons, $pricingCalculator, $couponCode, $validated) {
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
            }

            if (is_string($couponCode) && trim($couponCode) !== '') {
                $serviceSubtotal = round(
                    (float) $order->base_price + (float) $order->addons_total,
                    2,
                );
                $couponAdminMargin = (bool) $order->is_pricing_final
                    ? max(0.0, (float) $order->admin_margin_amount)
                    : (float) $pricingCalculator->provisional($serviceSubtotal, 0.0)['adminMargin'];
                $subtotal = round(
                    $serviceSubtotal
                    + (float) $order->travel_fee
                    + $couponAdminMargin,
                    2,
                );
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

                $discount = (float) $quote['discount'];
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
        $requestedWorkers = max(1, (int) ($validated['numberOfWorkers'] ?? 1));

        $validated['numberOfWorkers'] = max($requestedWorkers, count($preferredWorkerIds));

        return $validated;
    }

    /** @param array<string, mixed> $validated */
    private function assertWorkerCapacity(array $validated, UserCleaningOrderEstimationService $estimationService): void
    {
        $propertyType = (string) ($validated['propertyType'] ?? '');
        if ($estimationService->isEventAssistanceType($propertyType)) {
            return;
        }

        $estimation = $estimationService->estimate(
            $propertyType,
            (array) ($validated['propertyDetails'] ?? []),
        );
        $requiredWorkers = CleaningWorkerCapacity::requiredWorkers((float) $estimation['estimatedHours']);
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
