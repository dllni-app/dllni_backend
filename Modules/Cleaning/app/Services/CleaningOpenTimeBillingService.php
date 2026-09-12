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
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Support\CleaningRuntimeSettings;

final class CleaningOpenTimeBillingService
{
    public function __construct(
        private readonly CleaningPricingCalculator $pricingCalculator,
        private readonly CleaningCouponPricingService $couponPricingService,
    ) {}

    /** @return array<string, float|int> */
    public function preliminary(
        float $hourlyRate,
        int $workerCount,
        ?CleaningBillingPolicy $policy = null,
        int $expectedMaxMinutes = 480,
    ): array
    {
        $minimumMinutes = $this->minimumMinutes($policy);
        $roundingMinutes = $this->roundingMinutes($policy);
        $rules = is_array($policy?->rules) ? $policy->rules : [];
        $hardMaxMinutes = max(15, min(1440, (int) ($rules['hard_max_minutes'] ?? 480)));
        $expectedMaxMinutes = max(15, min($hardMaxMinutes, $expectedMaxMinutes));
        $warningMinutes = max(5, min($expectedMaxMinutes, (int) ($rules['warning_minutes'] ?? 30)));
        $extensionOptions = array_values(array_unique(array_filter(
            array_map('intval', (array) ($rules['extension_options'] ?? [15, 30, 60])),
            static fn (int $minutes): bool => $minutes > 0 && $minutes <= 240,
        )));
        $billableMinutes = $this->roundUp(max($minimumMinutes, 1), $roundingMinutes);
        $amount = $this->amount($hourlyRate, $workerCount, $billableMinutes);

        return [
            'hourlyRate' => round(max(0.0, $hourlyRate), 2),
            'requestedWorkerCount' => max(1, $workerCount),
            'minimumBillableMinutes' => $minimumMinutes,
            'roundingMinutes' => $roundingMinutes,
            'expectedMaxMinutes' => $expectedMaxMinutes,
            'hardMaxMinutes' => $hardMaxMinutes,
            'warningMinutes' => $warningMinutes,
            'extensionOptions' => $extensionOptions,
            'preliminaryBillableMinutes' => $billableMinutes,
            'preliminaryAmount' => $amount,
            'maximumEstimatedAmount' => $this->amount($hourlyRate, $workerCount, $expectedMaxMinutes),
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

        // Carbon 3 returns fractional minutes. Count any started minute before
        // applying the configured billing interval so sub-minute work is never
        // silently discarded from the authoritative duration snapshot.
        $actualMinutes = max(0, (int) ceil($booking->work_started_at->diffInSeconds($finishedAt) / 60));
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
        $now = now();
        $sessionsCount = $booking->sessions()
            ->where('session_type', CleaningBookingSession::TYPE_OPEN_TIME)
            ->count();
        $actualMinutes = $booking->work_started_at === null
            ? 0
            : max(0, (int) ceil(
                $booking->work_started_at->diffInSeconds($booking->work_finished_at ?? $now) / 60
            ));
        $minimumMinutes = max(1, (int) ($booking->open_time_minimum_minutes ?? 60));
        $roundingMinutes = max(1, (int) ($booking->open_time_rounding_minutes ?? 15));
        $liveBillableMinutes = $this->roundUp(max($actualMinutes, $minimumMinutes), $roundingMinutes);
        $liveAmount = $booking->open_time_hourly_rate === null
            ? null
            : $this->amount(
                (float) $booking->open_time_hourly_rate,
                max(1, (int) $booking->number_of_workers),
                $liveBillableMinutes,
            );
        $ceilingEndsAt = $booking->open_time_ceiling_ends_at
            ?? ($booking->work_started_at?->copy()->addMinutes((int) ($booking->open_time_expected_max_minutes ?? 480)));
        $pendingExtension = $booking->openTimeExtensions()
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        return [
            'isOpenTime' => $booking->booking_kind === 'open_time',
            'isMultiSession' => $sessionsCount > 1,
            'sessionsCount' => $sessionsCount,
            'hourlyRate' => $booking->open_time_hourly_rate !== null ? (float) $booking->open_time_hourly_rate : null,
            'requestedWorkerCount' => max(1, (int) $booking->number_of_workers),
            'minimumBillableMinutes' => $booking->open_time_minimum_minutes,
            'roundingMinutes' => $booking->open_time_rounding_minutes,
            'expectedMaxMinutes' => $booking->open_time_expected_max_minutes,
            'hardMaxMinutes' => $booking->open_time_hard_max_minutes,
            'warningMinutes' => $booking->open_time_warning_minutes,
            'extensionOptions' => $booking->open_time_extension_options ?? [],
            'ceilingEndsAt' => $ceilingEndsAt?->toIso8601String(),
            'remainingMinutes' => $ceilingEndsAt === null ? null : max(0, $now->diffInMinutes($ceilingEndsAt, false)),
            'remainingToCeilingMinutes' => $ceilingEndsAt === null ? null : max(0, $now->diffInMinutes($ceilingEndsAt, false)),
            'serverNow' => $now->toIso8601String(),
            'workStartedAt' => $booking->work_started_at?->toIso8601String(),
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'actualDurationMinutes' => $booking->open_time_actual_minutes,
            'billableDurationMinutes' => $booking->open_time_billable_minutes,
            'liveDurationMinutes' => $actualMinutes,
            'liveBillableMinutes' => $booking->open_time_finalized_at === null ? $liveBillableMinutes : $booking->open_time_billable_minutes,
            'liveAmount' => $booking->open_time_finalized_at === null ? $liveAmount : (float) $booking->open_time_final_amount,
            'endStatus' => $booking->open_time_end_status,
            'endRequestedAt' => $booking->open_time_end_requested_at?->toIso8601String(),
            'terminatedAt' => $booking->open_time_terminated_at?->toIso8601String(),
            'terminationReason' => $booking->open_time_termination_reason,
            'pendingExtension' => $pendingExtension === null ? null : [
                'id' => (int) $pendingExtension->id,
                'requestedMinutes' => (int) $pendingExtension->requested_minutes,
                'status' => (string) $pendingExtension->status,
                'workerId' => $pendingExtension->worker_id !== null ? (int) $pendingExtension->worker_id : null,
                'createdAt' => $pendingExtension->created_at?->toIso8601String(),
            ],
            'finalAmount' => $booking->open_time_final_amount !== null ? (float) $booking->open_time_final_amount : null,
            'isFinalized' => $booking->open_time_finalized_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    public function sessionPresentation(CleaningBookingSession $session): array
    {
        if ((string) $session->session_type !== CleaningBookingSession::TYPE_OPEN_TIME) {
            throw new InvalidArgumentException('Only Open-Time sessions have a live meter.');
        }

        $now = now();
        $snapshot = is_array($session->pricing_snapshot) ? $session->pricing_snapshot : [];
        $expectedMinutes = max(15, (int) ($session->open_time_expected_max_minutes ?? $snapshot['expectedMaxMinutes'] ?? 480));
        $minimumMinutes = max(1, (int) ($snapshot['minimumBillableMinutes'] ?? 60));
        $roundingMinutes = max(1, (int) ($snapshot['roundingMinutes'] ?? 15));
        $startedAt = $session->work_started_at;
        $finishedAt = $session->work_finished_at;
        $elapsedMinutes = $startedAt === null
            ? 0
            : max(0, (int) ceil($startedAt->diffInSeconds($finishedAt ?? $now) / 60));
        $billableMinutes = $this->roundUp(max($elapsedMinutes, $minimumMinutes), $roundingMinutes);
        $hourlyRate = max(0.0, (float) ($snapshot['hourlyRate'] ?? 0));
        $workerCount = max(1, (int) ($snapshot['workerCount'] ?? $session->requiredWorkerCount()));
        $liveAmount = $this->amount($hourlyRate, $workerCount, $billableMinutes);
        $ceilingEndsAt = $session->open_time_ceiling_ends_at
            ?? $startedAt?->copy()->addMinutes($expectedMinutes);
        $pendingExtension = $session->openTimeExtensions()
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        return [
            'isOpenTime' => true,
            'sessionId' => (int) $session->id,
            'hourlyRate' => $hourlyRate,
            'requestedWorkerCount' => $workerCount,
            'minimumBillableMinutes' => $minimumMinutes,
            'roundingMinutes' => $roundingMinutes,
            'expectedMaxMinutes' => $expectedMinutes,
            'hardMaxMinutes' => max($expectedMinutes, (int) ($session->open_time_hard_max_minutes ?? $snapshot['hardMaxMinutes'] ?? 480)),
            'warningMinutes' => max(1, (int) ($snapshot['warningMinutes'] ?? 30)),
            'extensionOptions' => array_values((array) ($snapshot['extensionOptions'] ?? [15, 30, 60])),
            'ceilingEndsAt' => $ceilingEndsAt?->toIso8601String(),
            'remainingMinutes' => $ceilingEndsAt === null ? null : max(0, (int) $now->diffInMinutes($ceilingEndsAt, false)),
            'remainingToCeilingMinutes' => $ceilingEndsAt === null ? null : max(0, (int) $now->diffInMinutes($ceilingEndsAt, false)),
            'serverNow' => $now->toIso8601String(),
            'workStartedAt' => $startedAt?->toIso8601String(),
            'workFinishedAt' => $finishedAt?->toIso8601String(),
            'liveDurationMinutes' => $elapsedMinutes,
            'liveBillableMinutes' => isset($snapshot['finalizedAt'])
                ? (int) ($snapshot['billableDurationMinutes'] ?? $billableMinutes)
                : $billableMinutes,
            'liveAmount' => (float) ($snapshot['finalAmount'] ?? $liveAmount),
            'actualDurationMinutes' => isset($snapshot['actualDurationMinutes']) ? (int) $snapshot['actualDurationMinutes'] : null,
            'billableDurationMinutes' => isset($snapshot['billableDurationMinutes']) ? (int) $snapshot['billableDurationMinutes'] : null,
            'finalAmount' => isset($snapshot['finalAmount']) ? (float) $snapshot['finalAmount'] : null,
            'isFinalized' => isset($snapshot['finalizedAt']),
            'endStatus' => $session->open_time_end_status,
            'endRequestedAt' => $session->open_time_end_requested_at?->toIso8601String(),
            'terminationReason' => $session->open_time_termination_reason,
            'pendingExtension' => $pendingExtension === null ? null : [
                'id' => (int) $pendingExtension->id,
                'requestedMinutes' => (int) $pendingExtension->requested_minutes,
                'status' => (string) $pendingExtension->status,
                'workerId' => $pendingExtension->worker_id !== null ? (int) $pendingExtension->worker_id : null,
                'createdAt' => $pendingExtension->created_at?->toIso8601String(),
            ],
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

        return max(1, min(120, (int) ($rules['rounding_minutes'] ?? 15)));
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
