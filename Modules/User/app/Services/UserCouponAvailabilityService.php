<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Validation\ValidationException;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\PromoCode;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmCoupon;

final class UserCouponAvailabilityService
{
    /**
     * @return array{
     *   section: string,
     *   couponCode: string,
     *   isAvailable: bool,
     *   reason: string,
     *   amounts: array{subtotal: float, discount: float, total: float},
     *   coupon: array{type: string|null, value: float|null, percent: float|null, minOrderAmount: float|null, maxDiscountAmount: float|null}|null
     * }
     */
    public function check(int $userId, string $section, ?string $couponCode): array
    {
        $couponCode = is_string($couponCode) ? mb_trim($couponCode) : '';
        if ($couponCode === '') {
            throw ValidationException::withMessages([
                'couponCode' => ['Coupon code is required.'],
            ]);
        }

        return match ($section) {
            'restaurants' => $this->checkRestaurantCoupon($userId, $couponCode),
            'supermarket' => $this->checkSupermarketCoupon($userId, $couponCode),
            default => throw ValidationException::withMessages([
                'section' => ['Invalid section.'],
            ]),
        };
    }

    /**
     * @return array{
     *   section: string,
     *   couponCode: string,
     *   isAvailable: bool,
     *   reason: string,
     *   amounts: array{subtotal: float, discount: float, total: float},
     *   coupon: array{type: string|null, value: float|null, percent: float|null, minOrderAmount: float|null, maxDiscountAmount: float|null}|null
     * }
     */
    private function checkRestaurantCoupon(int $userId, string $couponCode): array
    {
        $cart = Cart::query()
            ->where('user_id', $userId)
            ->with(['items.product'])
            ->latest()
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => ['Cart is empty.'],
            ]);
        }

        $merchantId = (int) $cart->restaurant_id;
        $subtotal = (float) $cart->items->sum(fn ($item): float => (float) ($item->total_price ?? 0));

        $coupon = PromoCode::query()
            ->where('restaurant_id', $merchantId)
            ->where('code', $couponCode)
            ->first();

        [$isAvailable, $reason] = $this->restaurantCouponStatus($coupon, $subtotal);
        $discount = $isAvailable ? $this->computeRestaurantDiscount($coupon, $subtotal) : 0.0;

        return [
            'section' => 'restaurants',
            'couponCode' => $couponCode,
            'isAvailable' => $isAvailable,
            'reason' => $reason,
            'amounts' => [
                'subtotal' => round($subtotal, 2),
                'discount' => round($discount, 2),
                'total' => round(max(0.0, $subtotal - $discount), 2),
            ],
            'coupon' => $coupon ? [
                'type' => $coupon->discount_type?->value ?? $coupon->discount_type,
                'value' => $coupon->discount_value !== null ? (float) $coupon->discount_value : null,
                'percent' => null,
                'minOrderAmount' => $coupon->min_order_amount !== null ? (float) $coupon->min_order_amount : null,
                'maxDiscountAmount' => null,
            ] : null,
        ];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function restaurantCouponStatus(?PromoCode $coupon, float $subtotal): array
    {
        if (! $coupon) {
            return [false, 'not_found'];
        }

        if (! $coupon->is_active) {
            return [false, 'inactive'];
        }

        if ($coupon->starts_at && now()->lt($coupon->starts_at)) {
            return [false, 'not_started'];
        }

        if ($coupon->ends_at && now()->gt($coupon->ends_at)) {
            return [false, 'expired'];
        }

        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            return [false, 'min_order_not_met'];
        }

        if ($coupon->usage_limit !== null && (int) $coupon->usage_count >= (int) $coupon->usage_limit) {
            return [false, 'usage_limit_reached'];
        }

        return [true, 'ok'];
    }

    private function computeRestaurantDiscount(PromoCode $coupon, float $subtotal): float
    {
        $type = $coupon->discount_type?->value ?? $coupon->discount_type;
        $value = (float) ($coupon->discount_value ?? 0);

        if ($type === 'percentage') {
            return round($subtotal * ($value / 100), 2);
        }

        return round(min($value, $subtotal), 2);
    }

    /**
     * @return array{
     *   section: string,
     *   couponCode: string,
     *   isAvailable: bool,
     *   reason: string,
     *   amounts: array{subtotal: float, discount: float, total: float},
     *   coupon: array{type: string|null, value: float|null, percent: float|null, minOrderAmount: float|null, maxDiscountAmount: float|null}|null
     * }
     */
    private function checkSupermarketCoupon(int $userId, string $couponCode): array
    {
        $cart = SmCart::query()
            ->where('user_id', $userId)
            ->with(['items.product'])
            ->latest()
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => ['Cart is empty.'],
            ]);
        }

        $merchantId = (int) $cart->store_id;
        $subtotal = (float) $cart->items->sum(fn ($item): float => (float) ($item->unit_price ?? 0) * (int) $item->quantity);

        $coupon = SmCoupon::query()
            ->where('store_id', $merchantId)
            ->where('code', $couponCode)
            ->first();

        [$isAvailable, $reason] = $this->supermarketCouponStatus($coupon, $subtotal);
        $discount = $isAvailable ? $this->computeSupermarketDiscount($coupon, $subtotal) : 0.0;

        return [
            'section' => 'supermarket',
            'couponCode' => $couponCode,
            'isAvailable' => $isAvailable,
            'reason' => $reason,
            'amounts' => [
                'subtotal' => round($subtotal, 2),
                'discount' => round($discount, 2),
                'total' => round(max(0.0, $subtotal - $discount), 2),
            ],
            'coupon' => $coupon ? [
                'type' => $coupon->type,
                'value' => $coupon->value !== null ? (float) $coupon->value : null,
                'percent' => $coupon->percent !== null ? (float) $coupon->percent : null,
                'minOrderAmount' => $coupon->min_order_amount !== null ? (float) $coupon->min_order_amount : null,
                'maxDiscountAmount' => $coupon->max_discount_amount !== null ? (float) $coupon->max_discount_amount : null,
            ] : null,
        ];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function supermarketCouponStatus(?SmCoupon $coupon, float $subtotal): array
    {
        if (! $coupon) {
            return [false, 'not_found'];
        }

        if (! $coupon->is_active) {
            return [false, 'inactive'];
        }

        if ($coupon->starts_at && now()->lt($coupon->starts_at)) {
            return [false, 'not_started'];
        }

        if ($coupon->ends_at && now()->gt($coupon->ends_at)) {
            return [false, 'expired'];
        }

        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            return [false, 'min_order_not_met'];
        }

        if ($coupon->usage_limit !== null && (int) $coupon->used_count >= (int) $coupon->usage_limit) {
            return [false, 'usage_limit_reached'];
        }

        return [true, 'ok'];
    }

    private function computeSupermarketDiscount(SmCoupon $coupon, float $subtotal): float
    {
        if ($coupon->type === 'percentage') {
            $amount = $subtotal * ((float) ($coupon->percent ?? 0) / 100);
            if ($coupon->max_discount_amount !== null) {
                $amount = min($amount, (float) $coupon->max_discount_amount);
            }

            return round($amount, 2);
        }

        return round(min((float) ($coupon->value ?? 0), $subtotal), 2);
    }
}
