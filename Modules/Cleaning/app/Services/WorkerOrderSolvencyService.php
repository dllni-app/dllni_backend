<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Throwable;

final class WorkerOrderSolvencyService
{
    public const REASON_ELIGIBLE = 'eligible';

    public const REASON_INSUFFICIENT_COMMISSION_CAPACITY = 'insufficient_commission_capacity';

    public const REASON_COMMISSION_UNAVAILABLE = 'commission_unavailable';

    public function __construct(
        private readonly CleaningPricingCalculator $pricingCalculator,
        private readonly DepositService $depositService,
        private readonly WorkerDebtService $debtService,
    ) {}

    public function solvencyPayloadForBooking(Worker $worker, CleaningBooking $booking, ?array $roomIds = null): array
    {
        $worker->loadMissing('deposit');
        $capacity = $this->workerCapacitySummary($worker, (int) $booking->id);
        $requiredCommission = 0.0;
        $reasonCode = self::REASON_ELIGIBLE;
        $message = 'Worker can cover this booking platform commission.';

        try {
            $requiredCommission = $this->requiredCommissionForBookingAndWorker($booking, $worker, $roomIds);
        } catch (Throwable $exception) {
            report($exception);
            $reasonCode = self::REASON_COMMISSION_UNAVAILABLE;
            $message = 'Platform commission cannot be calculated for this worker and booking.';
        }

        $canReceive = $reasonCode === self::REASON_ELIGIBLE
            && $worker->is_active
            && ! $worker->is_suspended
            && $this->depositService->isWorkerEligibleForDispatch($worker)
            && (float) $capacity['availableCommissionCapacity'] >= $requiredCommission;

        if (! $canReceive && $reasonCode === self::REASON_ELIGIBLE) {
            $reasonCode = self::REASON_INSUFFICIENT_COMMISSION_CAPACITY;
            $message = 'The available deposit and remaining debt capacity do not cover this booking platform commission.';
        }

        return array_merge($capacity, [
            'workerId' => (int) $worker->id,
            'bookingId' => (int) $booking->id,
            'requiredPlatformCommission' => round($requiredCommission, 2),
            'canReceiveOrder' => $canReceive,
            'canAcceptBooking' => $canReceive,
            'reasonCode' => $canReceive ? self::REASON_ELIGIBLE : $reasonCode,
            'message' => $message,
        ]);
    }

    public function canWorkerReceiveBooking(Worker $worker, CleaningBooking $booking): bool
    {
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
            throw new InvalidArgumentException('The available deposit and remaining debt capacity do not cover this booking platform commission.');
        }
    }

    public function workerCapacitySummary(Worker $worker, ?int $excludeBookingId = null): array
    {
        $worker->loadMissing('deposit');
        $limits = $this->depositService->resolveLimits($worker);
        $depositBalance = max(0.0, (float) ($worker->deposit?->current_balance ?? 0));
        $debtBalance = $this->debtService->outstandingAdministrationDue($worker);
        $allowedDebtLimit = max(0.0, (float) ($limits['maxNegativeBalance'] ?? 0));
        $remainingDebtCapacity = max(0.0, $allowedDebtLimit - $debtBalance);
        $activeReservedCommission = $this->activeReservedCommission($worker, $excludeBookingId);

        return [
            'currentBalance' => round($depositBalance, 2),
            'depositBalance' => round($depositBalance, 2),
            'allowedDebtLimit' => round($allowedDebtLimit, 2),
            'maxNegativeBalance' => round($allowedDebtLimit, 2),
            'currentDebtAmount' => round($debtBalance, 2),
            'remainingDebtCapacity' => round($remainingDebtCapacity, 2),
            'activeReservedCommission' => round($activeReservedCommission, 2),
            'availableCommissionCapacity' => $this->depositService->availableCommissionCapacity($worker, $activeReservedCommission),
        ];
    }

    public function requiredCommissionForBookingAndWorker(CleaningBooking $booking, Worker $worker, ?array $roomIds = null): float
    {
        $serviceShare = round(((float) ($booking->base_price ?? 0) + (float) ($booking->addons_total ?? 0)) / max(1, (int) ($booking->number_of_workers ?? 1)), 2);
        $pricing = $this->pricingCalculator->finalizedForWorker(
            $serviceShare,
            0.0,
            $booking->address_latitude !== null ? (float) $booking->address_latitude : null,
            $booking->address_longitude !== null ? (float) $booking->address_longitude : null,
            $worker,
        );

        return round((float) $pricing['adminMargin'], 2);
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
}
