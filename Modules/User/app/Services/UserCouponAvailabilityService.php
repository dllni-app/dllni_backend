<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\PlatformCoupon;
use App\Services\Coupons\PlatformCouponEligibilityService;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\PromoCode;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmCoupon;

final class UserCouponAvailabilityService
{
    public function __construct(
        private readonly PlatformCouponEligibilityService $platformEligibility,
        private readonly UserCleaningOrderEstimationService $cleaningEstimation,
    ) {}

    /** @return array<string, mixed> */
    public function check(int $userId, string $section, ?string $couponCode, array $context = []): array
    {
        $couponCode = mb_strtoupper(trim((string) $couponCode));
        if ($couponCode === '') {
            throw ValidationException::withMessages(['couponCode' => ['Coupon code is required.']]);
        }

        return match ($section) {
            PlatformCoupon::SECTION_RESTAURANT => $this->checkRestaurantCoupon($userId, $couponCode, $context),
            PlatformCoupon::SECTION_SUPERMARKET => $this->checkSupermarketCoupon($userId, $couponCode, $context),
            PlatformCoupon::SECTION_CLEANING => $this->checkCleaningCoupon($userId, $couponCode, $context),
            default => throw ValidationException::withMessages(['section' => ['Invalid section.']]),
        };
    }

    /** @return array<string, mixed> */
    private function checkRestaurantCoupon(int $userId, string $couponCode, array $context): array
    {
        $cart = $this->restaurantCart($userId, $context['cartId'] ?? null);
        $subtotal = (float) $cart->items->sum(fn ($item): float => (float) ($item->total_price ?? 0));

        if ($platform = $this->findPlatformCoupon($couponCode)) {
            return $this->platformResult($platform, $userId, PlatformCoupon::SECTION_RESTAURANT, $subtotal);
        }

        $coupon = PromoCode::query()
            ->where('restaurant_id', (int) $cart->restaurant_id)
            ->whereRaw('UPPER(code) = ?', [$couponCode])
            ->first();

        [$isValid, $reason] = $this->restaurantCouponStatus($coupon, $subtotal);
        $discount = $isValid ? $this->computeRestaurantDiscount($coupon, $subtotal) : 0.0;

        return $this->legacyResult(
            section: PlatformCoupon::SECTION_RESTAURANT,
            couponCode: $couponCode,
            isValid: $isValid,
            reason: $reason,
            subtotal: $subtotal,
            discount: $discount,
            coupon: $coupon ? [
                'id' => $coupon->id,
                'source' => 'restaurant',
                'type' => $coupon->discount_type?->value ?? $coupon->discount_type,
                'value' => $coupon->discount_value !== null ? (float) $coupon->discount_value : null,
                'percent' => null,
                'minOrderAmount' => $coupon->min_order_amount !== null ? (float) $coupon->min_order_amount : null,
                'maxDiscountAmount' => null,
                'expiresAt' => $coupon->ends_at?->toIso8601String(),
            ] : null,
        );
    }

    /** @return array<string, mixed> */
    private function checkSupermarketCoupon(int $userId, string $couponCode, array $context): array
    {
        $cart = $this->supermarketCart($userId, $context['cartId'] ?? null);
        $subtotal = (float) $cart->items->sum(fn ($item): float => (float) ($item->unit_price ?? 0) * (int) $item->quantity);

        if ($platform = $this->findPlatformCoupon($couponCode)) {
            return $this->platformResult($platform, $userId, PlatformCoupon::SECTION_SUPERMARKET, $subtotal);
        }

        $coupon = SmCoupon::query()
            ->where('store_id', (int) $cart->store_id)
            ->whereRaw('UPPER(code) = ?', [$couponCode])
            ->first();

        [$isValid, $reason] = $this->supermarketCouponStatus($coupon, $subtotal);
        $discount = $isValid ? $this->computeSupermarketDiscount($coupon, $subtotal) : 0.0;

        return $this->legacyResult(
            section: PlatformCoupon::SECTION_SUPERMARKET,
            couponCode: $couponCode,
            isValid: $isValid,
            reason: $reason,
            subtotal: $subtotal,
            discount: $discount,
            coupon: $coupon ? [
                'id' => $coupon->id,
                'source' => 'supermarket',
                'type' => $coupon->type,
                'value' => $coupon->value !== null ? (float) $coupon->value : null,
                'percent' => $coupon->percent !== null ? (float) $coupon->percent : null,
                'minOrderAmount' => $coupon->min_order_amount !== null ? (float) $coupon->min_order_amount : null,
                'maxDiscountAmount' => $coupon->max_discount_amount !== null ? (float) $coupon->max_discount_amount : null,
                'expiresAt' => $coupon->ends_at?->toIso8601String(),
            ] : null,
        );
    }

    /** @return array<string, mixed> */
    private function checkCleaningCoupon(int $userId, string $couponCode, array $context): array
    {
        $propertyType = (string) ($context['propertyType'] ?? '');
        $propertyDetails = (array) ($context['propertyDetails'] ?? []);
        $normalizedInput = $this->cleaningEstimation->pricingSnapshotInput(
            $propertyType,
            $propertyDetails,
            $context['addressLatitude'] ?? null,
            $context['addressLongitude'] ?? null,
            isset($context['preferredWorkerId']) ? (int) $context['preferredWorkerId'] : null,
        );
        $pricing = $this->cleaningEstimation->price(
            $normalizedInput['propertyType'],
            $normalizedInput['propertyDetails'],
            $normalizedInput['addressLatitude'],
            $normalizedInput['addressLongitude'],
            $normalizedInput['preferredWorkerId'],
        );
        $subtotal = round((float) $pricing['basePrice'] + (float) $pricing['addonsTotal'], 2);

        $coupon = $this->findPlatformCoupon($couponCode);
        if (! $coupon) {
            return $this->legacyResult(
                section: PlatformCoupon::SECTION_CLEANING,
                couponCode: $couponCode,
                isValid: false,
                reason: 'not_found',
                subtotal: $subtotal,
                discount: 0.0,
                coupon: null,
            );
        }

        return $this->platformResult(
            coupon: $coupon,
            userId: $userId,
            section: PlatformCoupon::SECTION_CLEANING,
            subtotal: $subtotal,
            context: [
                'propertyType' => $normalizedInput['propertyType'],
                'cleaningMode' => $normalizedInput['propertyDetails']['cleaning_mode'] ?? null,
                'eventType' => $normalizedInput['propertyDetails']['eventType'] ?? null,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function platformResult(
        PlatformCoupon $coupon,
        int $userId,
        string $section,
        float $subtotal,
        array $context = [],
    ): array {
        $coupon->loadMissing('constraints');
        $eligibility = $this->platformEligibility->evaluate($coupon, $userId, $section, $subtotal, $context);
        $discount = $eligibility['isValid']
            ? $this->platformEligibility->calculateDiscount($coupon, $subtotal)
            : 0.0;

        return $this->legacyResult(
            section: $section,
            couponCode: $coupon->code,
            isValid: $eligibility['isValid'],
            reason: $eligibility['reason'],
            subtotal: $subtotal,
            discount: $discount,
            coupon: [
                'id' => $coupon->id,
                'source' => 'platform',
                'title' => $coupon->localizedTitle(app()->getLocale()),
                'description' => $coupon->localizedDescription(app()->getLocale()),
                'type' => $coupon->discount_type,
                'value' => (float) $coupon->discount_value,
                'percent' => $coupon->discount_type === PlatformCoupon::DISCOUNT_PERCENTAGE ? (float) $coupon->discount_value : null,
                'minOrderAmount' => $coupon->min_order_amount !== null ? (float) $coupon->min_order_amount : null,
                'maxDiscountAmount' => $coupon->max_discount_amount !== null ? (float) $coupon->max_discount_amount : null,
                'expiresAt' => $coupon->expires_at?->toIso8601String(),
            ],
        );
    }

    private function findPlatformCoupon(string $couponCode): ?PlatformCoupon
    {
        return PlatformCoupon::query()->whereRaw('UPPER(code) = ?', [$couponCode])->first();
    }

    private function restaurantCart(int $userId, mixed $cartId): Cart
    {
        $query = Cart::query()->where('user_id', $userId)->with(['items.product']);
        $cart = is_numeric($cartId) ? $query->whereKey((int) $cartId)->first() : $query->latest()->first();

        if (! $cart || $cart->items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => ['Cart is empty or was not found.']]);
        }

        return $cart;
    }

    private function supermarketCart(int $userId, mixed $cartId): SmCart
    {
        $query = SmCart::query()->where('user_id', $userId)->with(['items.product']);
        $cart = is_numeric($cartId) ? $query->whereKey((int) $cartId)->first() : $query->latest()->first();

        if (! $cart || $cart->items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => ['Cart is empty or was not found.']]);
        }

        return $cart;
    }

    /** @return array{0: bool, 1: string} */
    private function restaurantCouponStatus(?PromoCode $coupon, float $subtotal): array
    {
        if (! $coupon) return [false, 'not_found'];
        if (! $coupon->is_active) return [false, 'inactive'];
        if ($coupon->starts_at && now()->lt($coupon->starts_at)) return [false, 'not_started'];
        if ($coupon->ends_at && now()->gt($coupon->ends_at)) return [false, 'expired'];
        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) return [false, 'min_order_not_met'];
        if ($coupon->usage_limit !== null && (int) $coupon->usage_count >= (int) $coupon->usage_limit) return [false, 'usage_limit_reached'];

        return [true, 'ok'];
    }

    private function computeRestaurantDiscount(PromoCode $coupon, float $subtotal): float
    {
        $type = $coupon->discount_type?->value ?? $coupon->discount_type;
        $value = (float) ($coupon->discount_value ?? 0);

        return $type === 'percentage'
            ? round($subtotal * ($value / 100), 2)
            : round(min($value, $subtotal), 2);
    }

    /** @return array{0: bool, 1: string} */
    private function supermarketCouponStatus(?SmCoupon $coupon, float $subtotal): array
    {
        if (! $coupon) return [false, 'not_found'];
        if (! $coupon->is_active) return [false, 'inactive'];
        if ($coupon->starts_at && now()->lt($coupon->starts_at)) return [false, 'not_started'];
        if ($coupon->ends_at && now()->gt($coupon->ends_at)) return [false, 'expired'];
        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) return [false, 'min_order_not_met'];
        if ($coupon->usage_limit !== null && (int) $coupon->used_count >= (int) $coupon->usage_limit) return [false, 'usage_limit_reached'];

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

    /** @return array<string, mixed> */
    private function legacyResult(
        string $section,
        string $couponCode,
        bool $isValid,
        string $reason,
        float $subtotal,
        float $discount,
        ?array $coupon,
    ): array {
        return [
            'section' => $section,
            'couponCode' => $couponCode,
            'isAvailable' => $isValid,
            'isValid' => $isValid,
            'reason' => $reason,
            'reasonCode' => $reason,
            'amounts' => [
                'subtotal' => round($subtotal, 2),
                'discount' => round($discount, 2),
                'total' => round(max(0.0, $subtotal - $discount), 2),
            ],
            'coupon' => $coupon,
        ];
    }
}
