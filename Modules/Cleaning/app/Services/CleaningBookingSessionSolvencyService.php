<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class CleaningBookingSessionSolvencyService
{
    public function __construct(
        private readonly WorkerOrderSolvencyService $bookingSolvencyService,
    ) {}

    /** @return array<string, mixed> */
    public function capacitySummary(Worker $worker): array
    {
        $base = $this->bookingSolvencyService->workerCapacitySummary($worker);
        $sessionReserved = $this->activeSessionReservedCommission($worker);
        $available = max(0.0, (float) ($base['availableCommissionCapacity'] ?? 0) - $sessionReserved);

        return array_merge($base, [
            'activeSessionReservedCommission' => round($sessionReserved, 2),
            'activeReservedCommission' => round((float) ($base['activeReservedCommission'] ?? 0) + $sessionReserved, 2),
            'availableCommissionCapacity' => round($available, 2),
        ]);
    }

    public function canCover(Worker $worker, float $requiredCommission): bool
    {
        if ($requiredCommission <= 0.0) {
            return true;
        }

        return (float) $this->capacitySummary($worker)['availableCommissionCapacity'] >= $requiredCommission;
    }

    public function assertCanCover(Worker $worker, float $requiredCommission): void
    {
        if ($this->canCover($worker, $requiredCommission)) {
            return;
        }

        throw new InvalidArgumentException(
            'The available deposit or remaining allowance does not cover this session platform commission.'
        );
    }

    private function activeSessionReservedCommission(Worker $worker): float
    {
        return round((float) CleaningBookingSessionWorkerAssignment::query()
            ->join(
                'cleaning_booking_sessions',
                'cleaning_booking_sessions.id',
                '=',
                'cleaning_booking_session_worker_assignments.cleaning_booking_session_id'
            )
            ->where('cleaning_booking_session_worker_assignments.worker_id', $worker->id)
            ->whereIn(
                'cleaning_booking_session_worker_assignments.status',
                CleaningBookingWorkerAssignmentStatus::activeValues(),
            )
            ->whereNotIn(
                'cleaning_booking_sessions.status',
                CleaningBookingSessionStatus::terminalValues(),
            )
            ->sum('cleaning_booking_session_worker_assignments.admin_margin_amount'), 2);
    }
}
