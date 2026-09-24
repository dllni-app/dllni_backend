<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use InvalidArgumentException;
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
            'totalPrice' => $this->roundMoney(
                $serviceSubtotal + $travelFee + max(0.0, $adminMargin - $includedAdminMargin)
            ),
            'isPricingFinal' => false,
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
        $addr = 'home_'.'address';
        $x = 'home_'.'latitude';
        $y = 'home_'.'longitude';

        if ($worker->{$addr} === null || mb_trim((string) $worker->{$addr}) === '' || $worker->{$x} === null || $worker->{$y} === null) {
            throw new InvalidArgumentException('Required pricing data is incomplete.');
        }

        return $this->finalizedForCoordinates(
            $basePrice,
            $addonsTotal,
            $a,
            $b,
            (float) $worker->{$x},
            (float) $worker->{$y},
            $includedAdminMarginBase,
        );
    }

    public function finalizedForCoordinates(
        float $basePrice,
        float $addonsTotal,
        ?float $a,
        ?float $b,
        float $c,
        float $d,
        float $includedAdminMarginBase = 0.0,
    ): array {
        if ($a === null || $b === null) {
            throw new InvalidArgumentException('Required pricing data is incomplete.');
        }

        $basePrice = $this->roundMoney($basePrice);
        $addonsTotal = $this->roundMoney($addonsTotal);
        $serviceSubtotal = $this->roundMoney($basePrice + $addonsTotal);
        $exactDistanceKm = $this->measureKm((float) $a, (float) $b, $c, $d);
        $distanceKm = round($exactDistanceKm, 3);

        $financial = CleaningRuntimeSettings::financial();
        $travelPerKm = max(0.0, (float) $financial->travel_per_km);
        $calculatedTravelFee = $this->roundMoney($exactDistanceKm * $travelPerKm);
        // The configured per-kilometre value is also the minimum transport fee.
        // This prevents very short routes from producing values such as 1 SYP.
        $distanceTravelFee = $travelPerKm > 0.0
            ? max($this->roundMoney($travelPerKm), $calculatedTravelFee)
            : 0.0;
        $travelFee = $this->roundMoney(
            $distanceTravelFee + $this->workerTransportAllowance($financial, 1)
        );
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
            'totalPrice' => $this->roundMoney(
                $serviceSubtotal + $travelFee + max(0.0, $adminMargin - $includedAdminMargin)
            ),
            'isPricingFinal' => true,
        ];
    }

    public function minimumOrderAdminMarginBase(float $basePrice, string $propertyType): float
    {
        if ($propertyType === 'event_assistance') {
            return 0.0;
        }

        $basePrice = $this->roundMoney(max(0.0, $basePrice));
        $minimumOrderPrice = $this->roundMoney(max(
            0.0,
            (float) (CleaningRuntimeSettings::financial()->cleaning_minimum_order_price ?? 0.0),
        ));

        return $minimumOrderPrice > 0.0 && $basePrice <= $minimumOrderPrice
            ? $basePrice
            : 0.0;
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
        if ((string) $financial->travel_markup_type !== 'worker_allowance') {
            return 0.0;
        }

        return $this->roundMoney(
            max(0.0, (float) $financial->travel_markup_value) * max(1, $workerCount)
        );
    }

    private function measureKm(float $a, float $b, float $c, float $d): float
    {
        $r = 6371.0;
        $da = deg2rad($c - $a);
        $db = deg2rad($d - $b);
        $h = sin($da / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin($db / 2) ** 2;

        return $r * (2 * atan2(sqrt($h), sqrt(1 - $h)));
    }
}
