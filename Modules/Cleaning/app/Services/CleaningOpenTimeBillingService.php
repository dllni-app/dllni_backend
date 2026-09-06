<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonInterface;
use InvalidArgumentException;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Support\CleaningRuntimeSettings;

final class CleaningOpenTimeBillingService
{
    /** @return array<string, float|int> */
    public function preliminary(float $hourlyRate, int $workerCount, ?CleaningBillingPolicy $policy = null): array
    {
        $minimumMinutes = $this->minimumMinutes($policy);
        $roundingMinutes = $this->roundingMinutes($policy);
        $billableMinutes = $this->roundUp(max($minimumMinutes, 1), $roundingMinutes);
        $amount = $this->amount($hourlyRate, $workerCount, $billableMinutes);

        return [
            'hourlyRate' => round(max(0.0, $hourlyRate), 2),
            'requestedWorkerCount' => max(1, $workerCount),
            'minimumBillableMinutes' => $minimumMinutes,
            'roundingMinutes' => $roundingMinutes,
            'preliminaryBillableMinutes' => $billableMinutes,
            'preliminaryAmount' => $amount,
        ];
    }

    /** @return array<string, float|int> */
    public function finalize(CleaningBooking $booking, CarbonInterface $finishedAt): array
    {
        if ($booking->booking_kind !== 'open_time') {
            throw new InvalidArgumentException('Only Open-Time bookings can be finalized by actual work duration.');
        }
        if ($booking->open_time_finalized_at !== null) {
            return $this->presentation($booking);
        }
        if ($booking->work_started_at === null) {
            throw new InvalidArgumentException('Open-Time billing requires an authoritative work start time.');
        }

        $actualMinutes = max(0, $booking->work_started_at->diffInMinutes($finishedAt));
        $minimumMinutes = max(1, (int) $booking->open_time_minimum_minutes);
        $roundingMinutes = max(1, (int) $booking->open_time_rounding_minutes);
        $billableMinutes = $this->roundUp(max($actualMinutes, $minimumMinutes), $roundingMinutes);
        $hourlyRate = max(0.0, (float) $booking->open_time_hourly_rate);
        $amount = $this->amount($hourlyRate, max(1, (int) $booking->number_of_workers), $billableMinutes);

        $booking->forceFill([
            'open_time_actual_minutes' => $actualMinutes,
            'open_time_billable_minutes' => $billableMinutes,
            'open_time_final_amount' => $amount,
            'open_time_finalized_at' => $finishedAt,
            'base_price' => $amount,
            'total_hours' => round($actualMinutes / 60, 2),
            'total_price' => round($amount + (float) $booking->addons_total + (float) $booking->travel_fee + (float) $booking->admin_margin_amount, 2),
            'is_pricing_final' => true,
        ])->save();

        return $this->presentation($booking->fresh());
    }

    /** @return array<string, mixed> */
    public function presentation(CleaningBooking $booking): array
    {
        return [
            'isOpenTime' => $booking->booking_kind === 'open_time',
            'hourlyRate' => $booking->open_time_hourly_rate !== null ? (float) $booking->open_time_hourly_rate : null,
            'requestedWorkerCount' => max(1, (int) $booking->number_of_workers),
            'minimumBillableMinutes' => $booking->open_time_minimum_minutes,
            'roundingMinutes' => $booking->open_time_rounding_minutes,
            'workStartedAt' => $booking->work_started_at?->toIso8601String(),
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'actualDurationMinutes' => $booking->open_time_actual_minutes,
            'billableDurationMinutes' => $booking->open_time_billable_minutes,
            'finalAmount' => $booking->open_time_final_amount !== null ? (float) $booking->open_time_final_amount : null,
            'isFinalized' => $booking->open_time_finalized_at !== null,
        ];
    }

    private function minimumMinutes(?CleaningBillingPolicy $policy): int
    {
        $rules = is_array($policy?->rules) ? $policy->rules : [];
        $configured = $rules['min_billable_minutes'] ?? $rules['min_billable_hours'] ?? null;
        if (is_numeric($configured)) {
            $minutes = (float) $configured;
            return max(1, (int) ($minutes <= 24 ? round($minutes * 60) : round($minutes)));
        }

        return max(1, (int) CleaningRuntimeSettings::financial()->min_billable_minutes);
    }

    private function roundingMinutes(?CleaningBillingPolicy $policy): int
    {
        $rules = is_array($policy?->rules) ? $policy->rules : [];

        return max(1, min(120, (int) ($rules['rounding_minutes'] ?? 30)));
    }

    private function roundUp(int $minutes, int $roundingMinutes): int
    {
        return (int) (ceil($minutes / $roundingMinutes) * $roundingMinutes);
    }

    private function amount(float $hourlyRate, int $workerCount, int $minutes): float
    {
        return round(max(0.0, $hourlyRate) * max(1, $workerCount) * ($minutes / 60), 2);
    }
}
