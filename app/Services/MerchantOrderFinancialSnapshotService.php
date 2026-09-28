<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformCoupon;
use App\Models\RestaurantFinancialSetting;
use App\Services\Coupons\PlatformCouponFundingAllocator;
use Illuminate\Database\Eloquent\Model;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Services\SmCommissionCalculator;

final class MerchantOrderFinancialSnapshotService
{
    public function __construct(
        private readonly PlatformCouponFundingAllocator $fundingAllocator,
        private readonly SmCommissionCalculator $supermarketCommissions,
    ) {}

    public function initializeRestaurant(Order $order): void
    {
        $this->persistRestaurant($order, null, 0.0);
    }

    public function initializeSupermarket(SmOrder $order): void
    {
        $this->persistSupermarket($order, null, 0.0);
    }

    /**
     * @return array{funding_type:string,platform_funded_amount:float,merchant_funded_amount:float,funding_snapshot:array<string,mixed>}
     */
    public function applyPlatformCoupon(Model $order, PlatformCoupon $coupon, float $discountAmount): array
    {
        if ($order instanceof Order) {
            return $this->persistRestaurant($order, $coupon, $discountAmount);
        }

        if ($order instanceof SmOrder) {
            return $this->persistSupermarket($order, $coupon, $discountAmount);
        }

        return [
            'funding_type' => $coupon->funding_type ?: PlatformCoupon::FUNDING_PLATFORM,
            'platform_funded_amount' => 0.0,
            'merchant_funded_amount' => 0.0,
            'funding_snapshot' => ['version' => 1, 'source' => 'not_applicable_to_order_type'],
        ];
    }

    public function markReversed(Model $order, string $reason): void
    {
        if (! $order instanceof Order && ! $order instanceof SmOrder) {
            return;
        }

        $snapshot = is_array($order->financial_snapshot) ? $order->financial_snapshot : [];
        $snapshot['reversal'] = [
            'reversed_at' => now()->toIso8601String(),
            'reason' => $reason,
        ];

        $order->forceFill([
            'financial_snapshot' => $snapshot,
            'financial_reversed_at' => now(),
        ])->saveQuietly();
    }
    /** @return array{funding_type:string,platform_funded_amount:float,merchant_funded_amount:float,funding_snapshot:array<string,mixed>} */
    private function persistRestaurant(Order $order, ?PlatformCoupon $coupon, float $platformCouponDiscount): array
    {
        $subtotal = round(max(0.0, (float) $order->subtotal), 2);
        $legacyMerchantDiscount = $coupon === null && $order->promo_code_id !== null
            ? round(max(0.0, (float) $order->discount_amount), 2)
            : 0.0;
        $platformDiscount = $coupon !== null ? round(max(0.0, $platformCouponDiscount), 2) : 0.0;
        $allocation = $this->fundingAllocation($coupon, $platformDiscount);

        $calculate = function (float $merchantFunded) use ($order, $subtotal, $legacyMerchantDiscount): array {
            $merchantGross = round(max(0.0, $subtotal - $legacyMerchantDiscount - $merchantFunded), 2);
            $setting = RestaurantFinancialSetting::query()->latest('id')->first();
            $commission = $this->restaurantCommission($setting, $merchantGross);
            $platformGross = round($commission + max(0.0, (float) $order->service_fee), 2);

            return [
                'merchant_gross_amount' => $merchantGross,
                'commission_amount' => $commission,
                'merchant_net_amount' => round(max(0.0, $merchantGross - $commission), 2),
                'platform_gross_revenue' => $platformGross,
                'commission_setting' => $setting,
            ];
        };

        $allocation = $this->applyRevenueCapIfNeeded($coupon, $platformDiscount, $allocation, $calculate);
        $settlement = $calculate($allocation['merchant_funded_amount']);
        $platformCost = round($allocation['platform_funded_amount'], 2);
        $platformNet = round($settlement['platform_gross_revenue'] - $platformCost, 2);
        $setting = $settlement['commission_setting'];

        $snapshot = [
            'version' => 1,
            'source' => 'restaurant_financial_snapshot',
            'calculated_at' => now()->toIso8601String(),
            'subtotal' => $subtotal,
            'customer_discount_amount' => round(max(0.0, (float) $order->discount_amount), 2),
            'merchant_coupon_discount_amount' => $legacyMerchantDiscount,
            'platform_coupon_discount_amount' => $platformDiscount,
            'merchant_gross_amount' => $settlement['merchant_gross_amount'],
            'commission_amount' => $settlement['commission_amount'],
            'merchant_net_amount' => $settlement['merchant_net_amount'],
            'platform_gross_revenue' => $settlement['platform_gross_revenue'],
            'platform_coupon_cost' => $platformCost,
            'platform_net_revenue' => $platformNet,
            'commission_setting' => $setting ? [
                'id' => $setting->id,
                'commission_type' => $setting->commission_type,
                'commission_value' => (float) $setting->commission_value,
            ] : null,
            'coupon_funding' => $allocation['snapshot'],
        ];

        $order->forceFill([
            'merchant_gross_amount' => $settlement['merchant_gross_amount'],
            'commission_amount' => $settlement['commission_amount'],
            'merchant_net_amount' => $settlement['merchant_net_amount'],
            'coupon_platform_funded_amount' => $allocation['platform_funded_amount'],
            'coupon_merchant_funded_amount' => $allocation['merchant_funded_amount'],
            'platform_gross_revenue' => $settlement['platform_gross_revenue'],
            'platform_coupon_cost' => $platformCost,
            'platform_net_revenue' => $platformNet,
            'financial_snapshot' => $snapshot,
            'financial_reversed_at' => null,
        ])->saveQuietly();

        return $this->redemptionFundingResult($allocation);
    }
    /** @return array{funding_type:string,platform_funded_amount:float,merchant_funded_amount:float,funding_snapshot:array<string,mixed>} */
    private function persistSupermarket(SmOrder $order, ?PlatformCoupon $coupon, float $platformCouponDiscount): array
    {
        $subtotal = round(max(0.0, (float) $order->subtotal), 2);
        $legacyMerchantDiscount = $coupon === null && $order->coupon_id !== null
            ? round(max(0.0, (float) $order->discount_amount), 2)
            : 0.0;
        $platformDiscount = $coupon !== null ? round(max(0.0, $platformCouponDiscount), 2) : 0.0;
        $allocation = $this->fundingAllocation($coupon, $platformDiscount);

        $calculate = function (float $merchantFunded) use ($order, $subtotal, $legacyMerchantDiscount): array {
            $merchantGross = round(max(0.0, $subtotal - $legacyMerchantDiscount - $merchantFunded), 2);
            $commission = $this->supermarketCommissions->snapshotForBase($order, $merchantGross);
            $platformGross = round((float) $commission['commission_amount'] + max(0.0, (float) $order->service_fee), 2);

            return [
                'merchant_gross_amount' => $merchantGross,
                'commission' => $commission,
                'platform_gross_revenue' => $platformGross,
            ];
        };

        $allocation = $this->applyRevenueCapIfNeeded($coupon, $platformDiscount, $allocation, $calculate);
        $settlement = $calculate($allocation['merchant_funded_amount']);
        $commission = $settlement['commission'];
        $platformCost = round($allocation['platform_funded_amount'], 2);
        $platformNet = round($settlement['platform_gross_revenue'] - $platformCost, 2);

        $snapshot = [
            'version' => 1,
            'source' => 'supermarket_financial_snapshot',
            'calculated_at' => now()->toIso8601String(),
            'subtotal' => $subtotal,
            'customer_discount_amount' => round(max(0.0, (float) $order->discount_amount), 2),
            'merchant_coupon_discount_amount' => $legacyMerchantDiscount,
            'platform_coupon_discount_amount' => $platformDiscount,
            'merchant_gross_amount' => $settlement['merchant_gross_amount'],
            'commission_amount' => (float) $commission['commission_amount'],
            'merchant_net_amount' => (float) $commission['store_net_amount'],
            'platform_gross_revenue' => $settlement['platform_gross_revenue'],
            'platform_coupon_cost' => $platformCost,
            'platform_net_revenue' => $platformNet,
            'commission_snapshot' => $commission['commission_snapshot'],
            'coupon_funding' => $allocation['snapshot'],
        ];

        $order->forceFill(array_merge($commission, [
            'merchant_gross_amount' => $settlement['merchant_gross_amount'],
            'merchant_net_amount' => (float) $commission['store_net_amount'],
            'coupon_platform_funded_amount' => $allocation['platform_funded_amount'],
            'coupon_merchant_funded_amount' => $allocation['merchant_funded_amount'],
            'platform_gross_revenue' => $settlement['platform_gross_revenue'],
            'platform_coupon_cost' => $platformCost,
            'platform_net_revenue' => $platformNet,
            'financial_snapshot' => $snapshot,
            'financial_reversed_at' => null,
        ]))->saveQuietly();

        return $this->redemptionFundingResult($allocation);
    }
    /** @return array{funding_type:string,platform_funded_amount:float,merchant_funded_amount:float,snapshot:array<string,mixed>} */
    private function fundingAllocation(?PlatformCoupon $coupon, float $discount): array
    {
        if ($coupon === null || $discount <= 0.0) {
            return [
                'funding_type' => PlatformCoupon::FUNDING_PLATFORM,
                'platform_funded_amount' => 0.0,
                'merchant_funded_amount' => 0.0,
                'snapshot' => [
                    'version' => 1,
                    'source' => 'no_platform_coupon',
                    'discount_amount' => 0.0,
                ],
            ];
        }

        return $this->fundingAllocator->initialAllocation($coupon, $discount);
    }

    /**
     * @param callable(float):array<string,mixed> $calculate
     * @param array{funding_type:string,platform_funded_amount:float,merchant_funded_amount:float,snapshot:array<string,mixed>} $allocation
     * @return array{funding_type:string,platform_funded_amount:float,merchant_funded_amount:float,snapshot:array<string,mixed>}
     */
    private function applyRevenueCapIfNeeded(?PlatformCoupon $coupon, float $discount, array $allocation, callable $calculate): array
    {
        if ($coupon === null || ! $coupon->cap_platform_funding_to_revenue || $discount <= 0.0) {
            $allocation['snapshot']['platform_funded_amount'] = $allocation['platform_funded_amount'];
            $allocation['snapshot']['merchant_funded_amount'] = $allocation['merchant_funded_amount'];

            return $allocation;
        }

        $requestedPlatform = $allocation['platform_funded_amount'];
        $platform = $requestedPlatform;
        $merchant = round(max(0.0, $discount - $platform), 2);

        for ($i = 0; $i < 12; $i++) {
            $settlement = $calculate($merchant);
            $allowed = round(max(0.0, (float) ($settlement['platform_gross_revenue'] ?? 0.0)), 2);
            $nextPlatform = round(min($requestedPlatform, $allowed), 2);
            $nextMerchant = round(max(0.0, $discount - $nextPlatform), 2);

            if (abs($nextPlatform - $platform) < 0.01) {
                $platform = $nextPlatform;
                $merchant = $nextMerchant;
                break;
            }

            $platform = $nextPlatform;
            $merchant = $nextMerchant;
        }

        $allocation['platform_funded_amount'] = $platform;
        $allocation['merchant_funded_amount'] = $merchant;
        $allocation['snapshot']['platform_funded_amount'] = $platform;
        $allocation['snapshot']['merchant_funded_amount'] = $merchant;
        $allocation['snapshot']['revenue_cap_applied'] = $platform < $requestedPlatform;
        $allocation['snapshot']['requested_platform_amount_before_revenue_cap'] = $requestedPlatform;

        return $allocation;
    }
    private function restaurantCommission(?RestaurantFinancialSetting $setting, float $merchantGross): float
    {
        if ($setting === null || $merchantGross <= 0.0) {
            return 0.0;
        }

        $commission = match ($setting->commission_type) {
            'percent' => $merchantGross * ((float) $setting->commission_value / 100),
            'fixed' => (float) $setting->commission_value,
            default => 0.0,
        };

        return round(max(0.0, min($merchantGross, $commission)), 2);
    }

    /**
     * @param array{funding_type:string,platform_funded_amount:float,merchant_funded_amount:float,snapshot:array<string,mixed>} $allocation
     * @return array{funding_type:string,platform_funded_amount:float,merchant_funded_amount:float,funding_snapshot:array<string,mixed>}
     */
    private function redemptionFundingResult(array $allocation): array
    {
        return [
            'funding_type' => $allocation['funding_type'],
            'platform_funded_amount' => round((float) $allocation['platform_funded_amount'], 2),
            'merchant_funded_amount' => round((float) $allocation['merchant_funded_amount'], 2),
            'funding_snapshot' => $allocation['snapshot'],
        ];
    }
}
