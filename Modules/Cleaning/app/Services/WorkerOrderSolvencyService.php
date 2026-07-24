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
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Throwable;

final class WorkerOrderSolvencyService
{
    public const REASON_ELIGIBLE = 'eligible';

    public const REASON_INSUFFICIENT_ADMINISTRATION_CAPACITY = 'insufficient_administration_capacity';

    public const REASON_ADMINISTRATION_DUE_UNAVAILABLE = 'administration_due_unavailable';

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
        $requiredAdministrationDue = 0.0;
        $workerOffer = null;
        $reasonCode = self::REASON_ELIGIBLE;
        $message = 'Worker can cover this booking administration due.';

        try {
            $workerOffer = $this->workerOfferForBooking($worker, $booking);
            $requiredAdministrationDue = (float) $workerOffer['adminMarginAmount'];
        } catch (Throwable $exception) {
            report($exception);
            $reasonCode = self::REASON_ADMINISTRATION_DUE_UNAVAILABLE;
            $message = 'Administration due cannot be calculated for this worker and booking.';
        }

        $canReceive = $reasonCode === self::REASON_ELIGIBLE
            && $worker->is_active
            && ! $worker->is_suspended
            && $this->depositService->isWorkerEligibleForDispatch($worker)
            && (float) $capacity['availableAdministrationCapacity'] >= $requiredAdministrationDue;

        if (! $canReceive && $reasonCode === self::REASON_ELIGIBLE) {
            $reasonCode = self::REASON_INSUFFICIENT_ADMINISTRATION_CAPACITY;
            $message = 'The available deposit and remaining debt capacity do not cover this booking administration due.';
        }

        return array_merge($capacity, [
            'workerId' => (int) $worker->id,
            'bookingId' => (int) $booking->id,
            'requiredAdministrationDue' => round($requiredAdministrationDue, 2),
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

    public function assertWorkerCanCoverAdministrationDue(Worker $worker, CleaningBooking $booking, float $requiredAmount): void
    {
        CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->lockForUpdate()->first();
        $capacity = $this->workerCapacitySummary($worker->fresh(['deposit']) ?? $worker, (int) $booking->id);

        if ((float) $capacity['availableAdministrationCapacity'] < $requiredAmount) {
            throw new InvalidArgumentException('The available deposit and remaining debt capacity do not cover this booking administration due.');
        }
    }

    /** @deprecated Use assertWorkerCanCoverAdministrationDue(). */
    public function assertWorkerCanCoverCommission(Worker $worker, CleaningBooking $booking, float $requiredCommission): void
    {
        $this->assertWorkerCanCoverAdministrationDue($worker, $booking, $requiredCommission);
    }

    public function workerCapacitySummary(Worker $worker, ?int $excludeBookingId = null): array
    {
        $worker->loadMissing('deposit');
        $limits = $this->depositService->resolveLimits($worker);
        $depositBalance = max(0.0, (float) ($worker->deposit?->current_balance ?? 0));
        $debtBalance = $this->debtService->indebtednessBalance($worker);
        $allowedDebtLimit = max(0.0, (float) ($limits['maxNegativeBalance'] ?? 0));
        $remainingDebtCapacity = max(0.0, $allowedDebtLimit - $debtBalance);
        $activeReservedAdministrationDue = $this->activeReservedAdministrationDue($worker, $excludeBookingId);

        return [
            'currentBalance' => round($depositBalance, 2),
            'depositBalance' => round($depositBalance, 2),
            'allowedDebtLimit' => round($allowedDebtLimit, 2),
            'maxNegativeBalance' => round($allowedDebtLimit, 2),
            'currentDebtAmount' => round($debtBalance, 2),
            'indebtednessBalance' => round($debtBalance, 2),
            'remainingDebtCapacity' => round($remainingDebtCapacity, 2),
            'activeReservedAdministrationDue' => round($activeReservedAdministrationDue, 2),
            'availableAdministrationCapacity' => $this->depositService->availableAdministrationCapacity($worker, $activeReservedAdministrationDue),
        ];
    }

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
            $workerAmount = (float) $assignment->worker_amount;

            return [
                'id' => (int) $assignment->id,
                'workerId' => (int) $assignment->worker_id,
                'status' => $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
                    ? $assignment->status->value
                    : (string) $assignment->status,
                'acceptedAt' => $assignment->accepted_at?->toIso8601String(),
                'roomCount' => (int) $assignment->room_count,
                'roomsWeight' => (float) $assignment->rooms_weight,
                'totalHours' => $totalHours,
                'serviceShareAmount' => $serviceShare,
                'travelFee' => $travelFee,
                'adminMarginAmount' => $adminMargin,
                'workerAmount' => $workerAmount,
                'totalPrice' => round($serviceShare + $travelFee + $adminMargin, 2),
                'currency' => (string) ($assignment->currency ?: config('app.currency', 'SYP')),
                'roomIds' => [],
                'isPricingFinal' => true,
                'isPreview' => false,
            ];
        }

        $workerCount = max(1, (int) ($booking->number_of_workers ?? 1));
        $serviceShare = round(
            ((float) ($booking->base_price ?? 0) + (float) ($booking->addons_total ?? 0)) / $workerCount,
            2,
        );
        $pricing = $this->pricingCalculator->finalizedForWorker(
            $serviceShare,
            0.0,
            $booking->address_latitude !== null ? (float) $booking->address_latitude : null,
            $booking->address_longitude !== null ? (float) $booking->address_longitude : null,
            $worker,
        );
        $travelFee = (float) $pricing['travelFee'];
        $adminMargin = (float) $pricing['adminMargin'];
        $workerAmount = round($serviceShare + $travelFee, 2);

        return [
            'id' => null,
            'workerId' => (int) $worker->id,
            'status' => null,
            'acceptedAt' => null,
            'roomCount' => 0,
            'roomsWeight' => 0.0,
            'totalHours' => $totalHours,
            'serviceShareAmount' => $serviceShare,
            'travelFee' => $travelFee,
            'adminMarginAmount' => $adminMargin,
            'workerAmount' => $workerAmount,
            'totalPrice' => round($workerAmount + $adminMargin, 2),
            'currency' => (string) config('app.currency', 'SYP'),
            'roomIds' => [],
            'isPricingFinal' => true,
            'isPreview' => true,
        ];
    }

    public function requiredAdministrationDueForBookingAndWorker(CleaningBooking $booking, Worker $worker, ?array $roomIds = null): float
    {
        return round((float) $this->workerOfferForBooking($worker, $booking)['adminMarginAmount'], 2);
    }

    /** @deprecated Use requiredAdministrationDueForBookingAndWorker(). */
    public function requiredCommissionForBookingAndWorker(CleaningBooking $booking, Worker $worker, ?array $roomIds = null): float
    {
        return $this->requiredAdministrationDueForBookingAndWorker($booking, $worker, $roomIds);
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

        return round($bookingHours / max(1, (int) ($booking->number_of_workers ?? 1)), 2);
    }

    private function activeReservedAdministrationDue(Worker $worker, ?int $excludeBookingId = null): float
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
}
