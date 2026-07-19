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

        $order = DB::transaction(function () use ($request, $service, $platformCoupons, $couponCode) {
            $order = $service->store($request->user(), $request->validated());

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
}
