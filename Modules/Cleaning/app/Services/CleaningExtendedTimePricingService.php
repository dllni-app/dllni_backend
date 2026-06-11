<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningExtendedTimePrice;

final class CleaningExtendedTimePricingService
{
    /**
     * @return array{
     *     requestedMinutes:int,
     *     matchedRange:array{id:int,startMinutes:int,endMinutes:int,label:string},
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

        $this->ensureFixedRanges();

        $range = CleaningExtendedTimePrice::query()
            ->where('start_minutes', '<=', $minutes)
            ->where('end_minutes', '>=', $minutes)
            ->orderBy('sort_order')
            ->first();

        if (! $range) {
            throw ValidationException::withMessages([
                'additionalMinutes' => ['No configured cleaning extension price range matches the requested minutes.'],
            ]);
        }

        return [
            'requestedMinutes' => $minutes,
            'matchedRange' => [
                'id' => (int) $range->id,
                'startMinutes' => (int) $range->start_minutes,
                'endMinutes' => (int) $range->end_minutes,
                'label' => $range->label(),
            ],
            'calculatedExtensionPrice' => round((float) $range->price, 2),
            'currency' => (string) config('app.currency', 'SYP'),
        ];
    }

    public function ensureFixedRanges(): void
    {
        $now = now();

        foreach (CleaningExtendedTimePrice::FIXED_RANGES as $range) {
            $exists = DB::table('cleaning_extended_time_prices')
                ->where('start_minutes', $range['start'])
                ->where('end_minutes', $range['end'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('cleaning_extended_time_prices')->insert([
                'start_minutes' => $range['start'],
                'end_minutes' => $range['end'],
                'price' => 0,
                'sort_order' => $range['sort'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
