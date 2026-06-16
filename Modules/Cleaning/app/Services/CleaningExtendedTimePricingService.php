<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningFinancialSetting;
use Illuminate\Validation\ValidationException;

final class CleaningExtendedTimePricingService
{
    private const CURRENCY = 'SYP';

    /**
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

        $ratePerThirtyMinutes = $this->ratePerThirtyMinutes();
        $range = $this->rangeForMinutes($minutes);

        if (! $range) {
            throw ValidationException::withMessages([
                'additionalMinutes' => ['No configured cleaning extension price range matches the requested minutes.'],
            ]);
        }

        $price = $this->priceForRange($range, $ratePerThirtyMinutes);

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
        $ratePerThirtyMinutes = $this->ratePerThirtyMinutes();

        return array_map(fn (array $range): array => [
            'id' => $range['sort'],
            'startMinutes' => $range['start'],
            'endMinutes' => $range['end'],
            'label' => $this->label($range),
            'price' => $this->priceForRange($range, $ratePerThirtyMinutes),
            'currency' => self::CURRENCY,
        ], self::FIXED_RANGES);
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
     * @return array{start:int,end:int,sort:int}|null
     */
    private function rangeForMinutes(int $minutes): ?array
    {
        foreach (self::FIXED_RANGES as $range) {
            if ($minutes >= $range['start'] && $minutes <= $range['end']) {
                return $range;
            }
        }

        return null;
    }

    /**
     * @param  array{start:int,end:int,sort:int}  $range
     */
    private function priceForRange(array $range, float $ratePerThirtyMinutes): float
    {
        return round(($ratePerThirtyMinutes / 30) * $range['end'], 2);
    }

    /**
     * @param  array{start:int,end:int,sort:int}  $range
     */
    private function label(array $range): string
    {
        return "من {$range['start']} إلى {$range['end']} دقيقة";
    }
}
