<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningFinancialSetting;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningExtendedTimePricingService
{
    private const CURRENCY = 'SYP';

    public function __construct(
        private readonly CleaningPricingCalculator $pricingCalculator,
    ) {}

    /**
     * Canonical 15-minute block boundaries (used as the fallback shape and to
     * validate configured ranges).
     *
     * @var array<int, array{start:int,end:int,sort:int}>
     */
    private const FIXED_RANGES = [
        ['start' => 0, 'end' => 15, 'sort' => 1],
        ['start' => 16, 'end' => 30, 'sort' => 2],
        ['start' => 31, 'end' => 45, 'sort' => 3],
        ['start' => 46, 'end' => 60, 'sort' => 4],
        ['start' => 61, 'end' => 75, 'sort' => 5],
        ['start' => 76, 'end' => 90, 'sort' => 6],
    ];

    /**
     * @return array{
     *     requestedMinutes:int,
     *     matchedRange:array{id:int,startMinutes:int,endMinutes:int,label:string,price:float,currency:string},
     *     calculatedExtensionPrice:float,
     *     currency:string
     * }
     */
    public function quote(int $minutes): array
    {
        if ($minutes < 0 || $minutes > 90) {
            throw ValidationException::withMessages([
                'additionalMinutes' => ['Extension minutes must be between 0 and 90.'],
            ]);
        }

        $range = $this->rangeForMinutes($minutes);

        if (! $range) {
            throw ValidationException::withMessages([
                'additionalMinutes' => ['No configured cleaning extension price range matches the requested minutes.'],
            ]);
        }

        $price = (float) $range['price'];

        return [
            'requestedMinutes' => $minutes,
            'matchedRange' => [
                'id' => $range['sort'],
                'startMinutes' => $range['start'],
                'endMinutes' => $range['end'],
                'label' => $this->label($range),
                'price' => $price,
                'currency' => self::CURRENCY,
            ],
            'calculatedExtensionPrice' => $price,
            'currency' => self::CURRENCY,
        ];
    }

    /**
     * @return array{
     *     requestedMinutes:int,
     *     matchedRange:array{id:int,startMinutes:int,endMinutes:int,label:string,price:float,baseAmount:float,adminMargin:float,totalAmount:float,currency:string},
     *     baseAmount:float,
     *     adminMargin:float,
     *     totalAmount:float,
     *     calculatedExtensionPrice:float,
     *     currency:string
     * }
     */
    public function quoteForBooking(CleaningBooking $booking, int $minutes): array
    {
        $quote = $this->quote($minutes);
        $baseAmount = (float) $quote['calculatedExtensionPrice'];
        $adminMargin = $this->extensionAdminMargin($booking, $baseAmount);
        $totalAmount = round($baseAmount + $adminMargin, 2);

        $quote['matchedRange'] = array_merge($quote['matchedRange'], [
            'price' => $totalAmount,
            'baseAmount' => $baseAmount,
            'adminMargin' => $adminMargin,
            'totalAmount' => $totalAmount,
        ]);
        $quote['baseAmount'] = $baseAmount;
        $quote['adminMargin'] = $adminMargin;
        $quote['totalAmount'] = $totalAmount;
        $quote['calculatedExtensionPrice'] = $totalAmount;

        return $quote;
    }

    /**
     * @return array<int, array{id:int,startMinutes:int,endMinutes:int,label:string,price:float,baseAmount:float,adminMargin:float,totalAmount:float,currency:string}>
     */
    public function rangesForBooking(CleaningBooking $booking): array
    {
        return array_map(function (array $range) use ($booking): array {
            $baseAmount = (float) $range['price'];
            $adminMargin = $this->extensionAdminMargin($booking, $baseAmount);
            $totalAmount = round($baseAmount + $adminMargin, 2);

            return array_merge($range, [
                'price' => $totalAmount,
                'baseAmount' => $baseAmount,
                'adminMargin' => $adminMargin,
                'totalAmount' => $totalAmount,
            ]);
        }, $this->ranges());
    }

    /**
     * @return array<int, array{id:int,startMinutes:int,endMinutes:int,label:string,price:float,currency:string}>
     */
    public function ranges(): array
    {
        return array_map(fn (array $range): array => [
            'id' => $range['sort'],
            'startMinutes' => $range['start'],
            'endMinutes' => $range['end'],
            'label' => $this->label($range),
            'price' => (float) $range['price'],
            'currency' => self::CURRENCY,
        ], $this->effectiveRanges());
    }

    /**
     * Effective priced ranges: admin-configured prices when available,
     * otherwise derived from the legacy per-30-minute rate (back-compat).
     *
     * @return array<int, array{start:int,end:int,sort:int,price:float}>
     */
    private function effectiveRanges(): array
    {
        $configured = $this->configuredRanges();

        if ($configured !== []) {
            return $configured;
        }

        $ratePerThirtyMinutes = $this->ratePerThirtyMinutes();

        return array_map(fn (array $range): array => [
            'start' => $range['start'],
            'end' => $range['end'],
            'sort' => $range['sort'],
            'price' => round(($ratePerThirtyMinutes / 30) * $range['end'], 2),
        ], self::FIXED_RANGES);
    }

    /**
     * @return array<int, array{start:int,end:int,sort:int,price:float}>
     */
    private function configuredRanges(): array
    {
        $ranges = CleaningFinancialSetting::query()->first()?->extension_ranges;

        if (! is_array($ranges) || $ranges === []) {
            return [];
        }

        $normalized = [];
        $sort = 1;

        foreach ($ranges as $range) {
            if (! is_array($range) || ! isset($range['start'], $range['end'], $range['price'])) {
                continue;
            }

            $normalized[] = [
                'start' => (int) $range['start'],
                'end' => (int) $range['end'],
                'sort' => $sort++,
                'price' => round((float) $range['price'], 2),
            ];
        }

        return $normalized;
    }

    private function ratePerThirtyMinutes(): float
    {
        $rate = (float) (CleaningFinancialSetting::query()->value('extension_rate_per_30_minutes') ?? 0);

        if ($rate <= 0) {
            throw ValidationException::withMessages([
                'extendedTimeRanges' => ['Cleaning extension rate is not configured.'],
            ]);
        }

        return $rate;
    }

    /**
     * @return array{start:int,end:int,sort:int,price:float}|null
     */
    private function rangeForMinutes(int $minutes): ?array
    {
        foreach ($this->effectiveRanges() as $range) {
            if ($minutes >= $range['start'] && $minutes <= $range['end']) {
                return $range;
            }
        }

        return null;
    }

    /**
     * @param  array{start:int,end:int,sort:int,price?:float}  $range
     */
    private function label(array $range): string
    {
        return "من {$range['start']} إلى {$range['end']} دقيقة";
    }

    private function extensionAdminMargin(CleaningBooking $booking, float $baseAmount): float
    {
        $serviceSubtotal = round(
            (float) ($booking->base_price ?? 0) + (float) ($booking->addons_total ?? 0),
            2,
        );
        $appliedExtensionMargin = (float) $booking->timeWarnings()
            ->whereNotNull('price_applied_at')
            ->sum('quoted_admin_margin_amount');
        $bookingAdminMargin = max(
            0.0,
            (float) ($booking->admin_margin_amount ?? 0) - $appliedExtensionMargin,
        );

        if ($serviceSubtotal <= 0.0 || $bookingAdminMargin <= 0.0 || $baseAmount <= 0.0) {
            return 0.0;
        }

        $effectiveRate = $bookingAdminMargin / $serviceSubtotal;

        return $this->pricingCalculator->roundMoney($baseAmount * $effectiveRate);
    }
}
