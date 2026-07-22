<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningFinancialSetting;
use Carbon\CarbonInterface;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningCancellationFeeCalculator
{
    /**
     * @return array{fee: float, percentage: float, hoursUntilStart: ?float}
     */
    public function forCustomer(CleaningBooking $booking, ?CarbonInterface $cancelledAt = null): array
    {
        return $this->calculate($booking, 'customer', $cancelledAt);
    }

    /**
     * @return array{fee: float, percentage: float, hoursUntilStart: ?float}
     */
    public function forWorker(CleaningBooking $booking, ?CarbonInterface $cancelledAt = null): array
    {
        return $this->calculate($booking, 'worker', $cancelledAt);
    }

    /**
     * @return array{fee: float, percentage: float, hoursUntilStart: ?float}
     */
    private function calculate(CleaningBooking $booking, string $actor, ?CarbonInterface $cancelledAt = null): array
    {
        $settings = CleaningFinancialSetting::query()->first();
        $base = max(0.0, (float) ($booking->total_price ?? 0));
        $cancelledAt ??= now();
        $hoursUntilStart = $this->hoursUntilStart($booking, $cancelledAt);

        $percentage = $actor === 'worker'
            ? max(0.0, (float) ($settings?->cancellation_worker_fee_percentage ?? 25))
            : $this->customerPercentage($settings, $hoursUntilStart);

        $fee = round($base * ($percentage / 100), 2);

        return [
            'fee' => $fee,
            'percentage' => $percentage,
            'hoursUntilStart' => $hoursUntilStart,
        ];
    }

    private function customerPercentage(?CleaningFinancialSetting $settings, ?float $hoursUntilStart): float
    {
        $freeUntil = (int) ($settings?->cancellation_user_free_until_hours ?? 24);
        $within24 = max(0.0, (float) ($settings?->cancellation_user_within_24h_percentage ?? 25));
        $within12 = max(0.0, (float) ($settings?->cancellation_user_within_12h_percentage ?? 50));

        if ($hoursUntilStart === null) {
            return $within24;
        }

        if ($hoursUntilStart >= $freeUntil) {
            return 0.0;
        }

        if ($hoursUntilStart < 12) {
            return $within12;
        }

        return $within24;
    }

    private function hoursUntilStart(CleaningBooking $booking, CarbonInterface $cancelledAt): ?float
    {
        $date = $booking->scheduled_date;
        $time = $booking->scheduled_time;

        if ($date === null) {
            return null;
        }

        $dateString = $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;
        $timeString = is_string($time) && $time !== '' ? $time : '00:00:00';
        $startsAt = \Illuminate\Support\Carbon::parse($dateString.' '.$timeString);

        return round(($startsAt->getTimestamp() - $cancelledAt->getTimestamp()) / 3600, 2);
    }
}
