<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Throwable;

final class WorkerOrderSolvencyService
{
    public const REASON_ELIGIBLE = 'eligible';

    public const REASON_INSUFFICIENT_COMMISSION_CAPACITY = 'insufficient_commission_capacity';

    public const REASON_COMMISSION_UNAVAILABLE = 'commission_unavailable';

    public const REASON_ALLOWANCE_LIMIT_EXHAUSTED = 'allowance_limit_exhausted';

    public function __construct(
        private readonly CleaningPricingCalculator $pricingCalculator,
        private readonly DepositService $depositService,
        private readonly WorkerDebtService $debtService,
        private readonly WorkerBookingScheduleConflictService $scheduleConflictService,
    ) {}

    public function solvencyPayloadForBooking(Worker $worker, CleaningBooking $booking, ?array $roomIds = null): array
    {
        $worker->loadMissing('deposit');
        $capacity = $this->workerCapacitySummary($worker, (int) $booking->id);
        $financialStatus = $this->depositService->depositStatusPayload($worker);
        $requiredCommission = 0.0;
        $workerOffer = null;
        $reasonCode = self::REASON_ELIGIBLE;
        $message = 'Worker can cover this booking platform commission.';

        try {
            $workerOffer = $this->workerOfferForBooking($worker, $booking);
            $requiredCommission = (float) $workerOffer['adminMarginAmount'];
        } catch (Throwable $exception) {
            report($exception);
            $reasonCode = self::REASON_COMMISSION_UNAVAILABLE;
            $message = 'Platform commission cannot be calculated for this worker and booking.';
        }

        $canReceive = $reasonCode === self::REASON_ELIGIBLE
            && $worker->is_active
            && ! $worker->is_suspended
            && (bool) ($financialStatus['isEligibleForNewRequests'] ?? false)
            && (float) $capacity['availableCommissionCapacity'] >= $requiredCommission;

        if (
            ! $canReceive
            && $reasonCode === self::REASON_ELIGIBLE
            && ($financialStatus['financialWarningCode'] ?? null) === 'deposit_below_minimum'
        ) {
            $reasonCode = 'deposit_required_before_start';
            $message = (string) ($financialStatus['financialWarningMessage'] ?? 'The worker deposit balance is below the minimum required amount.');
        } elseif (
            ! $canReceive
            && $reasonCode === self::REASON_ELIGIBLE
            && ($financialStatus['financialWarningCode'] ?? null) === 'allowance_limit_exhausted'
        ) {
            $reasonCode = self::REASON_ALLOWANCE_LIMIT_EXHAUSTED;
            $message = (string) ($financialStatus['financialWarningMessage'] ?? 'The worker allowance limit has been exhausted.');
        } elseif (
            ! $canReceive
            && $reasonCode === self::REASON_ELIGIBLE
            && (bool) ($capacity['isAllowanceLimitExhausted'] ?? false)
        ) {
            $reasonCode = self::REASON_ALLOWANCE_LIMIT_EXHAUSTED;
            $message = 'The worker allowance limit has been exhausted.';
        } elseif (! $canReceive && $reasonCode === self::REASON_ELIGIBLE) {
            $reasonCode = self::REASON_INSUFFICIENT_COMMISSION_CAPACITY;
            $message = 'The available deposit or remaining allowance does not cover this booking platform commission.';
        }

        return array_merge($capacity, [
            'workerId' => (int) $worker->id,
            'bookingId' => (int) $booking->id,
            'requiredPlatformCommission' => round($requiredCommission, 2),
            'workerOffer' => $workerOffer,
            'canReceiveOrder' => $canReceive,
            'canAcceptBooking' => $canReceive,
            'reasonCode' => $canReceive ? self::REASON_ELIGIBLE : $reasonCode,
            'message' => $message,
        ]);
    }

    public function canWorkerReceiveBooking(Worker $worker, CleaningBooking $booking): bool
    {
        if ($this->scheduleConflictService->hasConflict($worker, $booking)) {
            return false;
        }

        return (bool) $this->solvencyPayloadForBooking($worker, $booking)['canReceiveOrder'];
    }

    public function assertWorkerCanAcceptBooking(Worker $worker, CleaningBooking $booking, ?array $roomIds = null): void
    {
        CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->lockForUpdate()->first();
        $payload = $this->solvencyPayloadForBooking($worker->fresh(['deposit']) ?? $worker, $booking, $roomIds);

        if (! (bool) $payload['canAcceptBooking']) {
            throw new InvalidArgumentException((string) ($payload['message'] ?? 'Worker cannot accept this booking.'));
        }
    }

    public function assertWorkerCanCoverCommission(Worker $worker, CleaningBooking $booking, float $requiredCommission): void
    {
        CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->lockForUpdate()->first();
        $capacity = $this->workerCapacitySummary($worker->fresh(['deposit']) ?? $worker, (int) $booking->id);

        if ((float) $capacity['availableCommissionCapacity'] < $requiredCommission) {
            throw new InvalidArgumentException('The available deposit or remaining allowance does not cover this booking platform commission.');
        }
    }

    public function workerCapacitySummary(Worker $worker, ?int $excludeBookingId = null): array
    {
        $worker->loadMissing('deposit');
        $depositBalance = max(0.0, (float) ($worker->deposit?->current_balance ?? 0));
        $debtBalance = $this->debtService->indebtednessBalance($worker);
        $activeReservedCommission = $this->activeReservedCommission($worker, $excludeBookingId);
        $allowance = $this->depositService->allowanceSummary($worker, $activeReservedCommission);
        $configuredAllowedDebtLimit = max(0.0, (float) ($allowance['configuredAllowedDebtLimit'] ?? 0));
        $remainingAllowanceLimit = max(0.0, (float) ($allowance['remainingAllowanceLimit'] ?? 0));

        return [
            'currentBalance' => round($depositBalance, 2),
            'depositBalance' => round($depositBalance, 2),
            'allowedDebtLimit' => round($remainingAllowanceLimit, 2),
            'configuredAllowedDebtLimit' => round($configuredAllowedDebtLimit, 2),
            'maxNegativeBalance' => round($configuredAllowedDebtLimit, 2),
            'currentDebtAmount' => round($debtBalance, 2),
            'indebtednessBalance' => round($debtBalance, 2),
            'remainingDebtCapacity' => round($remainingAllowanceLimit, 2),
            'remainingAllowanceLimit' => round($remainingAllowanceLimit, 2),
            'allowanceUsedAmount' => round((float) ($allowance['allowanceUsedAmount'] ?? 0), 2),
            'adminCommissionBalance' => round((float) ($allowance['adminCommissionBalance'] ?? 0), 2),
            'withdrawnAdminRevenueTotal' => round((float) ($allowance['withdrawnAdminRevenueTotal'] ?? 0), 2),
            'isAllowanceLimitExhausted' => (bool) ($allowance['isAllowanceLimitExhausted'] ?? false),
            'allowanceWarningThresholdPercent' => round((float) ($allowance['allowanceWarningThresholdPercent'] ?? 10), 2),
            'isUsingDepositBalance' => (bool) ($allowance['isUsingDepositBalance'] ?? false),
            'isAllowanceNearLimit' => (bool) ($allowance['isAllowanceNearLimit'] ?? false),
            'activeReservedCommission' => round($activeReservedCommission, 2),
            'availableCommissionCapacity' => round((float) ($allowance['availableCommissionCapacity'] ?? 0), 2),
        ];
    }

    /**
     * Returns the current worker's financial view of the booking before or after acceptance.
     *
     * `totalPrice` is the worker's net expected earning. `grossTotalPrice` is
     * exposed separately for screens that need to show the amount before the
     * administration margin is deducted.
     *
     * Regular cleaning previews use the next available planned worker slot, so
     * the displayed service share matches the room plan shown to the customer.
     * Event-assistance orders intentionally remain evenly split between workers.
     *
     * @return array<string, mixed>
     */
    public function workerOfferForBooking(
        Worker $worker,
        CleaningBooking $booking,
        ?CleaningBookingWorkerAssignment $assignment = null,
    ): array {
        $assignment ??= $this->assignmentForWorker($booking, $worker);
        $totalHours = $this->workerDurationHours($booking);

        if ($assignment instanceof CleaningBookingWorkerAssignment && $this->isAcceptedAssignment($assignment)) {
            $serviceShare = (float) $assignment->service_share_amount;
            $travelFee = (float) $assignment->travel_fee;
            $adminMargin = (float) $assignment->admin_margin_amount;
            $grossWorkerTotal = $this->grossWorkerTotal($serviceShare, $travelFee);
            $workerAmount = $this->netWorkerAmount($serviceShare, $travelFee, $adminMargin);
            $isPricingFinal = (bool) $booking->is_pricing_final;

            return [
                'id' => (int) $assignment->id,
                'workerId' => (int) $assignment->worker_id,
                'status' => $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
                    ? $assignment->status->value
                    : (string) $assignment->status,
                'acceptedAt' => $assignment->accepted_at?->toIso8601String(),
                'roomCount' => (int) $assignment->room_count,
                'roomsWeight' => (float) $assignment->rooms_weight,
                'workerSlot' => null,
                'totalHours' => $totalHours,
                'serviceShareAmount' => $serviceShare,
                'travelFee' => $travelFee,
                'adminMarginAmount' => $adminMargin,
                'workerAmount' => $workerAmount,
                'totalPrice' => $workerAmount,
                'grossTotalPrice' => $grossWorkerTotal,
                'netTotalPrice' => $workerAmount,
                'currency' => (string) ($assignment->currency ?: config('app.currency', 'SYP')),
                'roomIds' => [],
                'isPricingFinal' => $isPricingFinal,
                'isPreview' => ! $isPricingFinal,
            ];
        }

        $preview = $this->previewServiceShare($booking);
        $serviceShare = $preview['serviceShareAmount'];
        $pricing = $this->pricingCalculator->finalizedForWorker(
            $serviceShare,
            0.0,
            $booking->address_latitude !== null ? (float) $booking->address_latitude : null,
            $booking->address_longitude !== null ? (float) $booking->address_longitude : null,
            $worker,
        );
        $travelFee = (float) $pricing['travelFee'];
        $adminMargin = (float) $pricing['adminMargin'];
        $grossWorkerTotal = $this->grossWorkerTotal($serviceShare, $travelFee);
        $workerAmount = $this->netWorkerAmount($serviceShare, $travelFee, $adminMargin);

        return [
            'id' => null,
            'workerId' => (int) $worker->id,
            'status' => null,
            'acceptedAt' => null,
            'roomCount' => $preview['roomCount'],
            'roomsWeight' => $preview['roomsWeight'],
            'workerSlot' => $preview['workerSlot'],
            'totalHours' => $totalHours,
            'serviceShareAmount' => $serviceShare,
            'travelFee' => $travelFee,
            'adminMarginAmount' => $adminMargin,
            'workerAmount' => $workerAmount,
            'totalPrice' => $workerAmount,
            'grossTotalPrice' => $grossWorkerTotal,
            'netTotalPrice' => $workerAmount,
            'currency' => (string) config('app.currency', 'SYP'),
            'roomIds' => $preview['roomIds'],
            'isPricingFinal' => false,
            'isPreview' => true,
        ];
    }

    public function requiredCommissionForBookingAndWorker(CleaningBooking $booking, Worker $worker, ?array $roomIds = null): float
    {
        return round((float) $this->workerOfferForBooking($worker, $booking)['adminMarginAmount'], 2);
    }

    private function assignmentForWorker(CleaningBooking $booking, Worker $worker): ?CleaningBookingWorkerAssignment
    {
        $assignment = $booking->relationLoaded('workerAssignments')
            ? $booking->workerAssignments->firstWhere('worker_id', $worker->id)
            : $booking->workerAssignments()->where('worker_id', $worker->id)->first();

        return $assignment instanceof CleaningBookingWorkerAssignment ? $assignment : null;
    }

    private function isAcceptedAssignment(CleaningBookingWorkerAssignment $assignment): bool
    {
        $status = $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
            ? $assignment->status->value
            : (string) $assignment->status;

        return in_array($status, CleaningBookingWorkerAssignmentStatus::acceptedValues(), true);
    }

    /**
     * @return array{serviceShareAmount:float, roomCount:int, roomsWeight:float, workerSlot:?int, roomIds:array<int, int>}
     */
    private function previewServiceShare(CleaningBooking $booking): array
    {
        $workerCount = max(1, (int) ($booking->number_of_workers ?? 1));
        $subtotal = round(
            (float) ($booking->base_price ?? 0) + (float) ($booking->addons_total ?? 0),
            2,
        );

        if ((string) $booking->property_type === 'event_assistance') {
            return [
                'serviceShareAmount' => round($subtotal / $workerCount, 2),
                'roomCount' => 0,
                'roomsWeight' => 0.0,
                'workerSlot' => null,
                'roomIds' => [],
            ];
        }

        $acceptedCount = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->count();
        $nextSlot = min($workerCount, $acceptedCount + 1);

        $plannedRooms = CleaningBookingRoom::query()
            ->where('cleaning_booking_id', $booking->id)
            ->whereNotNull('planned_worker_slot')
            ->get(['id', 'planned_worker_slot', 'weight']);

        $totalWeight = round((float) $plannedRooms->sum(
            static fn (CleaningBookingRoom $room): float => (float) $room->weight,
        ), 2);
        $slotRooms = $plannedRooms
            ->filter(static fn (CleaningBookingRoom $room): bool => (int) $room->planned_worker_slot === $nextSlot)
            ->values();
        $slotWeight = round((float) $slotRooms->sum(
            static fn (CleaningBookingRoom $room): float => (float) $room->weight,
        ), 2);

        if ($totalWeight <= 0.0 || $slotWeight <= 0.0) {
            return [
                'serviceShareAmount' => round($subtotal / $workerCount, 2),
                'roomCount' => 0,
                'roomsWeight' => 0.0,
                'workerSlot' => null,
                'roomIds' => [],
            ];
        }

        return [
            'serviceShareAmount' => round($subtotal * ($slotWeight / $totalWeight), 2),
            'roomCount' => $slotRooms->count(),
            'roomsWeight' => $slotWeight,
            'workerSlot' => $nextSlot,
            'roomIds' => $slotRooms->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        ];
    }

    private function workerDurationHours(CleaningBooking $booking): ?float
    {
        $details = is_array($booking->property_details) ? $booking->property_details : [];
        $bookingHours = (float) (
            $booking->total_hours
            ?: $booking->estimated_hours
            ?: Arr::get($details, 'hours', 0)
        );

        if ($bookingHours <= 0) {
            return null;
        }

        if ((string) $booking->property_type === 'event_assistance') {
            return round($bookingHours, 2);
        }

        return round($bookingHours / max(1, (int) ($booking->number_of_workers ?? 1)), 2);
    }

    private function activeReservedCommission(Worker $worker, ?int $excludeBookingId = null): float
    {
        $query = CleaningBookingWorkerAssignment::query()
            ->join('cleaning_bookings', 'cleaning_bookings.id', '=', 'cleaning_booking_worker_assignments.cleaning_booking_id')
            ->where('cleaning_booking_worker_assignments.worker_id', $worker->id)
            ->whereIn('cleaning_booking_worker_assignments.status', CleaningBookingWorkerAssignmentStatus::activeValues())
            ->whereNotIn('cleaning_bookings.status', [CleaningBookingStatus::Completed->value, CleaningBookingStatus::Cancelled->value]);

        if ($excludeBookingId !== null) {
            $query->where('cleaning_bookings.id', '!=', $excludeBookingId);
        }

        return round((float) $query->sum('cleaning_booking_worker_assignments.admin_margin_amount'), 2);
    }

    private function grossWorkerTotal(float $serviceShare, float $travelFee): float
    {
        return round($serviceShare + $travelFee, 2);
    }

    private function netWorkerAmount(float $serviceShare, float $travelFee, float $adminMargin): float
    {
        return max(0.0, round($this->grossWorkerTotal($serviceShare, $travelFee) - $adminMargin, 2));
    }
}
