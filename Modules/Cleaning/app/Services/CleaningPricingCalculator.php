<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use Modules\Cleaning\Support\CleaningRuntimeSettings;

final class CleaningPricingCalculator
{
    /**
     * Prices are stored in the new SYP units, so monetary calculations must no
     * longer be rounded up to 500 SYP. Keep the smallest cash increment at one
     * lira to avoid inflating room prices, hourly rates, and commissions.
     */
    private const SYRIAN_CASH_INCREMENT = 1.0;

    public function provisional(
        float $basePrice,
        float $addonsTotal = 0.0,
        float $includedAdminMarginBase = 0.0,
        int $workerCount = 1,
    ): array {

        $basePrice = $this->roundMoney($basePrice);
        $addonsTotal = $this->roundMoney($addonsTotal);
        $serviceSubtotal = $this->roundMoney($basePrice + $addonsTotal);
        $financial = CleaningRuntimeSettings::financial();
        $adminMargin = $this->adminMargin($serviceSubtotal, $financial);
        $includedAdminMargin = $this->includedAdminMargin(
            $serviceSubtotal,
            $includedAdminMarginBase,
            $financial,
            $adminMargin,
        );
        $travelFee = $this->workerTransportAllowance($financial, $workerCount);

        return [
            'travelFee' => $travelFee,
            'distanceKm' => null,
            'adminMargin' => $adminMargin,
            'includedAdminMargin' => $includedAdminMargin,
            'totalPrice' => $this->roundMoney($serviceSubtotal + $travelFee),
            // Transport is a configured worker allowance, not a future route quote.
            'isPricingFinal' => true,
        ];
    }

    public function finalizedForWorker(
        float $basePrice,
        float $addonsTotal,
        ?float $a,
        ?float $b,
        Worker $worker,
        float $includedAdminMarginBase = 0.0,
    ): array {
        $financial = CleaningRuntimeSettings::financial();

        return $this->finalizedPricing(
            $basePrice,
            $addonsTotal,
            $this->workerTransportAllowance($financial, 1),
            null,
            $includedAdminMarginBase,
            $financial,
        );
    }

    private function finalizedPricing(
        float $basePrice,
        float $addonsTotal,
        float $travelFee,
        ?float $distanceKm,
        float $includedAdminMarginBase,
        CleaningFinancialSetting $financial,
    ): array {
        $basePrice = $this->roundMoney($basePrice);
        $addonsTotal = $this->roundMoney($addonsTotal);
        $serviceSubtotal = $this->roundMoney($basePrice + $addonsTotal);
        $travelFee = $this->roundMoney(max(0.0, $travelFee));
        $adminMargin = $this->adminMargin($serviceSubtotal, $financial);
        $includedAdminMargin = $this->includedAdminMargin(
            $serviceSubtotal,
            $includedAdminMarginBase,
            $financial,
            $adminMargin,
        );

        return [
            'travelFee' => $travelFee,
            'distanceKm' => $distanceKm,
            'adminMargin' => $adminMargin,
            'includedAdminMargin' => $includedAdminMargin,
            'totalPrice' => $this->roundMoney($serviceSubtotal + $travelFee),
            'isPricingFinal' => true,
        ];
    }

    public function minimumOrderAdminMarginBase(
        float $basePrice,
        string $propertyType,
        float $addonsTotal = 0.0,
        float $travelFee = 0.0,
        ?float $customerTotalPrice = null,
    ): float {
        if ($propertyType === 'event_assistance') {
            return 0.0;
        }

        $serviceSubtotal = $this->roundMoney(max(0.0, $basePrice) + max(0.0, $addonsTotal));
        $minimumOrderPrice = $this->roundMoney(max(
            0.0,
            (float) (CleaningRuntimeSettings::financial()->cleaning_minimum_order_price ?? 0.0),
        ));

        // Minimum is commission-inclusive only when the actual whole-order
        // subtotal, including travel, was topped up to the configured floor.
        $subtotalWithTravel = $serviceSubtotal + $this->roundMoney(max(0.0, $travelFee));
        if ($minimumOrderPrice <= 0.0 || abs($subtotalWithTravel - $minimumOrderPrice) >= 0.01) {
            return 0.0;
        }

        // Hitting the threshold naturally must not cancel the commission.
        // A commission-inclusive minimum is identifiable because the stored
        // customer total equals the base + extras + transport (no margin on top).
        if ($customerTotalPrice !== null
            && abs($this->roundMoney($customerTotalPrice) - $subtotalWithTravel) >= 0.01
        ) {
            return 0.0;
        }

        return $serviceSubtotal;
    }

    public function roundMoney(float $amount): float
    {
        if ($amount <= 0.0) {
            return 0.0;
        }

        return (float) (ceil($amount / self::SYRIAN_CASH_INCREMENT) * self::SYRIAN_CASH_INCREMENT);
    }

    private function includedAdminMargin(
        float $serviceSubtotal,
        float $includedAdminMarginBase,
        CleaningFinancialSetting $financial,
        float $adminMargin,
    ): float {
        $includedBase = min(
            $serviceSubtotal,
            $this->roundMoney(max(0.0, $includedAdminMarginBase)),
        );

        if ($includedBase <= 0.0 || $adminMargin <= 0.0) {
            return 0.0;
        }

        return min($adminMargin, $this->adminMargin($includedBase, $financial));
    }

    private function adminMargin(float $serviceSubtotal, CleaningFinancialSetting $financial): float
    {
        $commissionType = (string) $financial->commission_type;

        return $commissionType === 'fixed'
            ? $this->roundMoney(max(0.0, (float) ($financial->commission_fixed_amount ?? 0.0)))
            : $this->roundMoney(
                $serviceSubtotal * (max(0.0, (float) $financial->default_commission_rate) / 100)
            );
    }

    private function workerTransportAllowance(CleaningFinancialSetting $financial, int $workerCount): float
    {
        return $this->roundMoney(
            max(0.0, (float) $financial->travel_markup_value) * max(1, $workerCount)
        );
    }
}
