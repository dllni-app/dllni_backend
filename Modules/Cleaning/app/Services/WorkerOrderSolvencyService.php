<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class WorkerOrderSolvencyService
{
    public const REASON_ELIGIBLE = 'eligible';
    public const REASON_INSUFFICIENT_COMMISSION_CAPACITY = 'insufficient_commission_capacity';

    public function __construct(
        private readonly CleaningPricingCalculator $pricingCalculator,
        private readonly DepositService $depositService,
    ) {}

    public function solvencyPayloadForBooking(Worker $worker, CleaningBooking $booking, ?array $roomIds = null): array
    {
        $worker->loadMissing('deposit');
        $capacity = $this->workerCapacitySummary($worker, (int) $booking->id);
        $requiredCommission = $this->requiredCommissionForBookingAndWorker($booking, $worker);
        $canReceive = $worker->is_active
            && ! $worker->is_suspended
            && $this->depositService->isWorkerEligibleForDispatch($worker)
            && $capacity['availableCommissionCapacity'] >= $requiredCommission;

        return array_merge($capacity, [
            'workerId' => (int) $worker->id,
            'bookingId' => (int) $booking->id,
            'requiredPlatformCommission' => round($requiredCommission, 2),
            'canReceiveOrder' => $canReceive,
            'canAcceptBooking' => $canReceive,
            'reasonCode' => $canReceive ? self::REASON_ELIGIBLE : self::REASON_INSUFFICIENT_COMMISSION_CAPACITY,
        ]);
    }

    public function canWorkerReceiveBooking(Worker $worker, CleaningBooking $booking): bool
    {
        try {
            return (bool) $this->solvencyPayloadForBooking($worker, $booking)['canReceiveOrder'];
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function assertWorkerCanAcceptBooking(Worker $worker, CleaningBooking $booking, ?array $roomIds = null): void
    {
        CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->lockForUpdate()->first();

        $payload = $this->solvencyPayloadForBooking($worker->fresh(['deposit']) ?? $worker, $booking, $roomIds);

        if (! (bool) $payload['canAcceptBooking']) {
            throw new InvalidArgumentException('Worker balance and allowed negative limit do not cover this booking platform commission.');
        }
    }

    public function assertWorkerCanCoverCommission(Worker $worker, CleaningBooking $booking, float $requiredCommission): void
    {
        CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->lockForUpdate()->first();
        $capacity = $this->workerCapacitySummary($worker->fresh(['deposit']) ?? $worker, (int) $booking->id);

        if ((float) $capacity['availableCommissionCapacity'] < $requiredCommission) {
            throw new InvalidArgumentException('Worker balance and allowed negative limit do not cover this booking platform commission.');
        }
    }

    public function workerCapacitySummary(Worker $worker, ?int $excludeBookingId = null): array
    {
        $worker->loadMissing('deposit');
        $limits = $this->depositService->resolveLimits($worker);
        $currentBalance = (float) ($worker->deposit?->current_balance ?? 0);
        $allowedNegativeLimit = (float) ($limits['maxNegativeBalance'] ?? 0);
        $activeReservedCommission = $this->activeReservedCommission($worker, $excludeBookingId);

        return [
            'currentBalance' => round($currentBalance, 2),
            'allowedDebtLimit' => round($allowedNegativeLimit, 2),
            'currentDebtAmount' => round(max(0, -$currentBalance), 2),
            'activeReservedCommission' => round($activeReservedCommission, 2),
            'availableCommissionCapacity' => round($currentBalance + $allowedNegativeLimit - $activeReservedCommission, 2),
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
            ->whereIn('cleaning_booking_worker_assignments.status', ['accepted', 'accepted_waiting_team', 'accepted_waiting_for_order_start'])
            ->whereNotIn('cleaning_bookings.status', [CleaningBookingStatus::Completed->value, CleaningBookingStatus::Cancelled->value]);

        if ($excludeBookingId !== null) {
            $query->where('cleaning_bookings.id', '!=', $excludeBookingId);
        }

        return round((float) $query->sum('cleaning_booking_worker_assignments.admin_margin_amount'), 2);
    }
}
