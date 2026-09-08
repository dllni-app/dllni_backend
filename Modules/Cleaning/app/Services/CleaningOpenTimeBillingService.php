<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Support\CleaningRuntimeSettings;

final class CleaningOpenTimeBillingService
{
    public function __construct(
        private readonly CleaningPricingCalculator $pricingCalculator,
        private readonly CleaningCouponPricingService $couponPricingService,
    ) {}

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

    /**
     * Finalize a persisted Open-Time booking outside a pending model save.
     *
     * The booking save is quiet because this service explicitly applies the same
     * canonical team-pricing/coupon effects required by terminal settlement. This
     * prevents pricing observers from re-normalizing a finalized multi-worker
     * amount after assignment snapshots have already been repriced.
     *
     * @return array<string, float|int|string|bool|null>
     */
    public function finalize(CleaningBooking $booking, CarbonInterface $finishedAt): array
    {
        if ($booking->booking_kind !== 'open_time') {
            throw new InvalidArgumentException('Only Open-Time bookings can be finalized by actual work duration.');
        }

        $finalizedBooking = DB::transaction(function () use ($booking, $finishedAt): CleaningBooking {
            $lockedBooking = CleaningBooking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->open_time_finalized_at !== null) {
                return $lockedBooking;
            }

            $this->prepareFinalPricing($lockedBooking, $finishedAt);
            $lockedBooking->saveQuietly();

            return $lockedBooking->fresh();
        });

        return $this->presentation($finalizedBooking);
    }

    /**
     * Mutate the booking and its worker financial snapshots before the booking's
     * terminal save is persisted. This is intentionally callable from an
     * `updating` observer so final Open-Time pricing exists before any `updated`
     * observer can debit the administration commission.
     */
    public function prepareFinalPricing(CleaningBooking $booking, CarbonInterface $finishedAt): void
    {
        if ($booking->booking_kind !== 'open_time') {
            throw new InvalidArgumentException('Only Open-Time bookings can be finalized by actual work duration.');
        }
        if ($booking->open_time_finalized_at !== null) {
            return;
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
        $serviceSubtotal = round($amount + max(0.0, (float) $booking->addons_total), 2);

        $assignmentPricing = $this->repriceWorkerAssignments($booking, $serviceSubtotal);
        $travelFee = $assignmentPricing['hasAssignments']
            ? $assignmentPricing['travelFee']
            : round(max(0.0, (float) $booking->travel_fee), 2);
        $adminMargin = $assignmentPricing['hasAssignments']
            ? $assignmentPricing['adminMargin']
            : round(max(0.0, (float) $booking->admin_margin_amount), 2);

        $booking->forceFill([
            'open_time_actual_minutes' => $actualMinutes,
            'open_time_billable_minutes' => $billableMinutes,
            'open_time_final_amount' => $amount,
            'open_time_finalized_at' => $finishedAt,
            'base_price' => $amount,
            'total_hours' => round($actualMinutes / 60, 2),
            'travel_fee' => $travelFee,
            'admin_margin_amount' => $adminMargin,
            'total_price' => round($serviceSubtotal + $travelFee + $adminMargin, 2),
            'is_pricing_final' => true,
        ]);

        // Reuse the platform's canonical admin-first coupon allocator after the
        // gross Open-Time/team snapshots have been repriced. This keeps travel
        // untouched and only reduces worker service share after admin reaches 0.
        if ((int) ($booking->platform_coupon_id ?? 0) > 0) {
            $this->couponPricingService->applyBeforeSave($booking);
        }
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

    /**
     * Reprice the gross assignment snapshots using the proportions already
     * produced by CleaningBookingTeamService. Open-Time therefore does not own a
     * second payout formula: it preserves the canonical team allocation and only
     * scales it to the authoritative final service subtotal. Per-worker travel
     * and administration values still come from CleaningPricingCalculator.
     *
     * @return array{hasAssignments: bool, travelFee: float, adminMargin: float}
     */
    private function repriceWorkerAssignments(CleaningBooking $booking, float $serviceSubtotal): array
    {
        $assignments = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->with('worker')
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->get();

        if ($assignments->isEmpty()) {
            return [
                'hasAssignments' => false,
                'travelFee' => 0.0,
                'adminMargin' => 0.0,
            ];
        }

        $storedServiceTotal = (float) $assignments->sum(
            static fn (CleaningBookingWorkerAssignment $assignment): float => max(
                0.0,
                (float) ($assignment->service_share_amount ?? 0),
            ),
        );
        $remainingService = round(max(0.0, $serviceSubtotal), 2);
        $totalTravelFee = 0.0;
        $totalAdminMargin = 0.0;
        $count = $assignments->count();

        foreach ($assignments->values() as $index => $assignment) {
            $isLast = $index === $count - 1;
            $storedShare = max(0.0, (float) ($assignment->service_share_amount ?? 0));

            if ($isLast) {
                $serviceShare = round(max(0.0, $remainingService), 2);
            } elseif ($storedServiceTotal > 0.0) {
                $serviceShare = round($serviceSubtotal * ($storedShare / $storedServiceTotal), 2);
                $serviceShare = min($serviceShare, max(0.0, $remainingService));
            } else {
                $serviceShare = round($serviceSubtotal / $count, 2);
                $serviceShare = min($serviceShare, max(0.0, $remainingService));
            }

            $remainingService = round($remainingService - $serviceShare, 2);
            $travelFee = 0.0;
            $adminMargin = 0.0;
            $worker = $assignment->worker;

            if (
                $worker instanceof Worker
                && $booking->address_latitude !== null
                && $booking->address_longitude !== null
            ) {
                $pricing = $this->pricingCalculator->finalizedForWorker(
                    $serviceShare,
                    0.0,
                    (float) $booking->address_latitude,
                    (float) $booking->address_longitude,
                    $worker,
                );
                $travelFee = (float) $pricing['travelFee'];
                $adminMargin = (float) $pricing['adminMargin'];
            }

            $assignment->forceFill([
                'service_share_amount' => $serviceShare,
                'travel_fee' => $travelFee,
                'admin_margin_amount' => $adminMargin,
                'worker_amount' => max(0.0, round($serviceShare + $travelFee, 2)),
            ])->saveQuietly();

            $totalTravelFee += $travelFee;
            $totalAdminMargin += $adminMargin;
        }

        return [
            'hasAssignments' => true,
            'travelFee' => round($totalTravelFee, 2),
            'adminMargin' => round($totalAdminMargin, 2),
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
