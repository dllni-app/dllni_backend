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

    public function provisional(float $basePrice, float $addonsTotal = 0.0): array
    {
        $basePrice = $this->roundMoney($basePrice);
        $addonsTotal = $this->roundMoney($addonsTotal);
        $serviceSubtotal = $this->roundMoney($basePrice + $addonsTotal);
        $financial = CleaningRuntimeSettings::financial();
        $adminMargin = $this->adminMargin($serviceSubtotal, $financial);

        return [
            'travelFee' => 0.0,
            'distanceKm' => null,
            'adminMargin' => $adminMargin,
            'totalPrice' => $this->roundMoney($serviceSubtotal + $adminMargin),
            'isPricingFinal' => false,
        ];
    }

    public function finalizedForWorker(float $basePrice, float $addonsTotal, ?float $a, ?float $b, Worker $worker): array
    {
        $addr = 'home_'.'address';
        $x = 'home_'.'latitude';
        $y = 'home_'.'longitude';

        if ($worker->{$addr} === null || mb_trim((string) $worker->{$addr}) === '' || $worker->{$x} === null || $worker->{$y} === null) {
            throw new InvalidArgumentException('Required pricing data is incomplete.');
        }

        return $this->finalizedForCoordinates($basePrice, $addonsTotal, $a, $b, (float) $worker->{$x}, (float) $worker->{$y});
    }

    public function finalizedForCoordinates(float $basePrice, float $addonsTotal, ?float $a, ?float $b, float $c, float $d): array
    {
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
        $travelFee = $this->roundMoney($exactDistanceKm * $travelPerKm);
        $adminMargin = $this->adminMargin($serviceSubtotal, $financial);

        return [
            'travelFee' => $travelFee,
            'distanceKm' => $distanceKm,
            'adminMargin' => $adminMargin,
            'totalPrice' => $this->roundMoney($serviceSubtotal + $travelFee + $adminMargin),
            'isPricingFinal' => true,
        ];
    }

    public function roundMoney(float $amount): float
    {
        if ($amount <= 0.0) {
            return 0.0;
        }

        return (float) (ceil($amount / self::SYRIAN_CASH_INCREMENT) * self::SYRIAN_CASH_INCREMENT);
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

    private function measureKm(float $a, float $b, float $c, float $d): float
    {
        $r = 6371.0;
        $da = deg2rad($c - $a);
        $db = deg2rad($d - $b);
        $h = sin($da / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin($db / 2) ** 2;

        return $r * (2 * atan2(sqrt($h), sqrt(1 - $h)));
    }
}
