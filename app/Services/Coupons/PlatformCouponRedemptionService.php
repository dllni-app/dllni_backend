<?php

declare(strict_types=1);

namespace App\Services\Coupons;

use App\Models\PlatformCoupon;
use App\Models\PlatformCouponRedemption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class PlatformCouponRedemptionService
{
    public function __construct(private readonly PlatformCouponEligibilityService $eligibility) {}

    /** @return array{coupon: PlatformCoupon, discount: float}|null */
    public function preview(
        int $userId,
        string $section,
        ?string $couponCode,
        float $subtotal,
        array $context = [],
        bool $required = false,
    ): ?array {
        return $this->resolve($userId, $section, $couponCode, $subtotal, $context, false, $required);
    }

    /** @return array{coupon: PlatformCoupon, discount: float}|null */
    public function quoteForPlacement(
        int $userId,
        string $section,
        ?string $couponCode,
        float $subtotal,
        array $context = [],
        bool $required = false,
    ): ?array {
        return $this->resolve($userId, $section, $couponCode, $subtotal, $context, true, $required);
    }

    public function record(
        PlatformCoupon $coupon,
        int $userId,
        string $section,
        float $subtotal,
        float $discount,
        Model $order,
    ): PlatformCouponRedemption {
        $redemption = PlatformCouponRedemption::query()->create([
            'platform_coupon_id' => $coupon->id,
            'user_id' => $userId,
            'section' => $section,
            'order_type' => $order->getMorphClass(),
            'order_id' => $order->getKey(),
            'coupon_code' => $coupon->code,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'redeemed_at' => now(),
        ]);

        $coupon->increment('used_count');
        $order->forceFill([
            'platform_coupon_id' => $coupon->id,
            'platform_coupon_code' => $coupon->code,
        ])->save();

        return $redemption;
    }

    /** @return array{coupon: PlatformCoupon, discount: float}|null */
    private function resolve(
        int $userId,
        string $section,
        ?string $couponCode,
        float $subtotal,
        array $context,
        bool $lock,
        bool $required,
    ): ?array {
        $normalizedCode = mb_strtoupper(trim((string) $couponCode));
        if ($normalizedCode === '') {
            return null;
        }

        $query = PlatformCoupon::query()->with('constraints')->whereRaw('UPPER(code) = ?', [$normalizedCode]);
        if ($lock) {
            $query->lockForUpdate();
        }

        $coupon = $query->first();
        if (! $coupon) {
            if ($required) {
                throw ValidationException::withMessages(['couponCode' => ['not_found']]);
            }

            return null;
        }

        $result = $this->eligibility->evaluate($coupon, $userId, $section, $subtotal, $context);
        if (! $result['isValid']) {
            throw ValidationException::withMessages(['couponCode' => [$result['reason']]]);
        }

        return [
            'coupon' => $coupon,
            'discount' => $this->eligibility->calculateDiscount($coupon, $subtotal),
        ];
    }
}
