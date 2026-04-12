<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Models\PromoCode;

final class UserRestaurantActiveCouponsController
{
    public function __invoke(): JsonResponse
    {
        $now = now();

        $coupons = PromoCode::query()
            ->with([
                'restaurant' => fn ($query) => $query
                    ->select(['id', 'name', 'slug', 'is_active'])
                    ->with('media'),
            ])
            ->whereHas('restaurant', fn ($query) => $query->where('is_active', true))
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(fn ($query) => $query->whereNull('usage_limit')->orWhereColumn('usage_count', '<', 'usage_limit'))
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PromoCode $coupon): array => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'discountType' => $coupon->discount_type?->value ?? $coupon->discount_type,
                'discountValue' => (float) ($coupon->discount_value ?? 0),
                'minOrderAmount' => $coupon->min_order_amount !== null ? (float) $coupon->min_order_amount : null,
                'usageLimit' => $coupon->usage_limit,
                'usageCount' => (int) $coupon->usage_count,
                'startsAt' => $coupon->starts_at?->toDateTimeString(),
                'endsAt' => $coupon->ends_at?->toDateTimeString(),
                'isActive' => (bool) $coupon->is_active,
                'restaurant' => [
                    'id' => $coupon->restaurant?->id,
                    'name' => $coupon->restaurant?->name,
                    'slug' => $coupon->restaurant?->slug,
                    'imageUrl' => $coupon->restaurant?->getFirstMediaUrl('primary-image') ?: null,
                ],
            ])
            ->values()
            ->all();

        return response()->json([
            'coupons' => $coupons,
        ]);
    }
}
