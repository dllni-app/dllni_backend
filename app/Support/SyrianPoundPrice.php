<?php

declare(strict_types=1);

namespace App\Support;

final class SyrianPoundPrice
{
    /** @var array<int, int> */
    public const PRICE_POINTS = [10, 25, 50, 100, 200, 500];

    private const LEGACY_SCALE_THRESHOLD = 1000;

    private const LEGACY_SCALE_DIVISOR = 1000;

    public static function normalize(float|int|string|null $amount): int
    {
        $value = self::convertedValue($amount);

        if ($value <= 0.0) {
            return 0;
        }

        foreach (self::PRICE_POINTS as $pricePoint) {
            if ($value <= $pricePoint) {
                return $pricePoint;
            }
        }

        return 500;
    }

    public static function normalizeDiscount(
        float|int|string|null $discountedAmount,
        float|int|string|null $regularAmount,
    ): ?int {
        if ($discountedAmount === null || $discountedAmount === '') {
            return null;
        }

        $discounted = self::convertedValue($discountedAmount);
        if ($discounted <= 0.0) {
            return 0;
        }

        $regular = self::normalize($regularAmount);
        $normalizedDiscount = self::floorPricePoint($discounted);

        if ((float) $discountedAmount < (float) $regularAmount && $normalizedDiscount >= $regular) {
            return self::previousPricePoint($regular) ?? $regular;
        }

        return min($normalizedDiscount, $regular);
    }

    private static function convertedValue(float|int|string|null $amount): float
    {
        if (! is_numeric($amount)) {
            return 0.0;
        }

        $value = max(0.0, (float) $amount);

        if ($value >= self::LEGACY_SCALE_THRESHOLD) {
            $value /= self::LEGACY_SCALE_DIVISOR;
        }

        return $value;
    }

    private static function floorPricePoint(float $value): int
    {
        $selected = self::PRICE_POINTS[0];

        foreach (self::PRICE_POINTS as $pricePoint) {
            if ($pricePoint > $value) {
                break;
            }

            $selected = $pricePoint;
        }

        return $selected;
    }

    private static function previousPricePoint(int $pricePoint): ?int
    {
        $previous = null;

        foreach (self::PRICE_POINTS as $candidate) {
            if ($candidate >= $pricePoint) {
                return $previous;
            }

            $previous = $candidate;
        }

        return $previous;
    }
}
