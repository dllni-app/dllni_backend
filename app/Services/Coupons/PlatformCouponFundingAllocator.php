<?php

declare(strict_types=1);

namespace App\Services\Coupons;

use App\Models\PlatformCoupon;

final class PlatformCouponFundingAllocator
{
    /**
     * @return array{
     *   funding_type:string,
     *   platform_funded_amount:float,
     *   merchant_funded_amount:float,
     *   snapshot:array<string,mixed>
     * }
     */
    public function initialAllocation(PlatformCoupon $coupon, float $discountAmount): array
    {
        $discount = round(max(0.0, $discountAmount), 2);
        $fundingType = $coupon->funding_type ?: PlatformCoupon::FUNDING_PLATFORM;

        $platformPercent = match ($fundingType) {
            PlatformCoupon::FUNDING_MERCHANT => 0.0,
            PlatformCoupon::FUNDING_SHARED => max(0.0, min(100.0, (float) $coupon->platform_funding_percent)),
            default => 100.0,
        };

        $platformAmount = round($discount * ($platformPercent / 100), 2);

        if ($coupon->max_platform_funding_amount !== null) {
            $platformAmount = min($platformAmount, max(0.0, (float) $coupon->max_platform_funding_amount));
        }

        $platformAmount = round(max(0.0, min($discount, $platformAmount)), 2);
        $merchantAmount = round(max(0.0, $discount - $platformAmount), 2);

        return [
            'funding_type' => $fundingType,
            'platform_funded_amount' => $platformAmount,
            'merchant_funded_amount' => $merchantAmount,
            'snapshot' => [
                'version' => 1,
                'funding_type' => $fundingType,
                'requested_platform_percent' => (float) $coupon->platform_funding_percent,
                'requested_merchant_percent' => (float) $coupon->merchant_funding_percent,
                'max_platform_funding_amount' => $coupon->max_platform_funding_amount !== null
                    ? (float) $coupon->max_platform_funding_amount
                    : null,
                'cap_platform_funding_to_revenue' => (bool) $coupon->cap_platform_funding_to_revenue,
                'discount_amount' => $discount,
            ],
        ];
    }
}
