<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\PromoCode;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerCouponSummaryController
{
    public function __invoke(RestaurantOwnerContext $context): JsonResponse
    {
        $restaurant = $context->restaurant();
        $now = now();

        $coupons = PromoCode::query()->where('restaurant_id', $restaurant->id)->get();

        $activeCount = $coupons->filter(function (PromoCode $coupon) use ($now): bool {
            return $coupon->is_active
                && (! $coupon->starts_at || $coupon->starts_at->lte($now))
                && (! $coupon->ends_at || $coupon->ends_at->gte($now));
        })->count();

        $expiredCount = $coupons->filter(function (PromoCode $coupon) use ($now): bool {
            return ! $coupon->is_active || ($coupon->ends_at && $coupon->ends_at->lt($now));
        })->count();

        $couponIds = $coupons->pluck('id');

        $orders = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('promo_code_id', $couponIds)
            ->get();

        $topCoupon = $coupons->sortByDesc('usage_count')->first();

        return response()->json([
            'summary' => [
                'activeCount' => $activeCount,
                'expiredCount' => $expiredCount,
                'totalUsageOrders' => $orders->count(),
                'totalSavings' => (float) $orders->sum('discount_amount'),
                'revenueImpact' => (float) $orders->sum('total_amount'),
                'topPerforming' => $topCoupon ? [
                    'id' => $topCoupon->id,
                    'code' => $topCoupon->code,
                    'usageCount' => (int) $topCoupon->usage_count,
                ] : null,
            ],
        ]);
    }
}
