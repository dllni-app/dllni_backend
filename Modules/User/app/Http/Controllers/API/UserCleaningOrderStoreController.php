<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\PlatformCoupon;
use App\Services\Coupons\PlatformCouponRedemptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\User\Http\Requests\UserCleaningOrderStoreRequest;
use Modules\User\Services\UserCleaningOrderEstimationService;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderStoreController
{
    public function __invoke(
        UserCleaningOrderStoreRequest $request,
        UserCleaningOrderService $service,
        PlatformCouponRedemptionService $platformCoupons,
    ): JsonResponse {
        $couponCode = $request->input('couponCode');
        Validator::make(['couponCode' => $couponCode], [
            'couponCode' => ['nullable', 'string', 'max:50'],
        ])->validate();
        $validated = $this->withEventWorkerCount($request->validated());

        $order = DB::transaction(function () use ($request, $service, $platformCoupons, $couponCode, $validated) {
            $order = $service->store($request->user(), $validated);

            if (is_string($couponCode) && trim($couponCode) !== '') {
                $subtotal = round((float) $order->base_price + (float) $order->addons_total, 2);
                $quote = $platformCoupons->quoteForPlacement(
                    userId: (int) $request->user()->id,
                    section: PlatformCoupon::SECTION_CLEANING,
                    couponCode: $couponCode,
                    subtotal: $subtotal,
                    context: [
                        'propertyType' => (string) $order->property_type,
                        'cleaningMode' => $order->property_details['cleaning_mode'] ?? null,
                        'eventType' => $order->property_details['eventType'] ?? null,
                    ],
                    required: true,
                );

                $discount = (float) $quote['discount'];
                $order->forceFill([
                    'subtotal_before_discount' => $subtotal,
                    'discount_amount' => $discount,
                    'total_price' => round(max(0.0, (float) $order->total_price - $discount), 2),
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

        return response()->json(['order' => CleaningBookingResource::make($order)], 201);
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
