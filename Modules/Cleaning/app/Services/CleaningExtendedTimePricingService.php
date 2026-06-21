<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningFinancialSetting;
use Illuminate\Validation\ValidationException;

final class CleaningExtendedTimePricingService
{
    private const CURRENCY = 'SYP';

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
}
