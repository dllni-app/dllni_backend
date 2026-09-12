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
        private readonly DepositService $depositService,
    ) {}

    /** @return array<string, mixed> */
    public function capacitySummary(Worker $worker): array
    {
        $worker->loadMissing('deposit');
        $base = $this->bookingSolvencyService->workerCapacitySummary($worker);
        $sessionReserved = $this->activeSessionReservedCommission($worker);
        $available = max(0.0, (float) ($base['availableCommissionCapacity'] ?? 0) - $sessionReserved);
        $financialStatus = $this->depositService->depositStatusPayload($worker);
        $eligible = (bool) ($financialStatus['isEligibleForNewRequests'] ?? false)
            && (bool) $worker->is_active
            && ! (bool) $worker->is_suspended;

        return array_merge($base, [
            'activeSessionReservedCommission' => round($sessionReserved, 2),
            'activeReservedCommission' => round((float) ($base['activeReservedCommission'] ?? 0) + $sessionReserved, 2),
            'availableCommissionCapacity' => round($available, 2),
            'isEligibleForNewSessionRequests' => $eligible,
            'financialWarningCode' => $financialStatus['financialWarningCode'] ?? null,
            'financialWarningMessage' => $financialStatus['financialWarningMessage'] ?? null,
        ]);
    }

    public function canCover(Worker $worker, float $requiredCommission): bool
    {
        $summary = $this->capacitySummary($worker);

        if (! (bool) ($summary['isEligibleForNewSessionRequests'] ?? false)) {
            return false;
        }

        if ($requiredCommission <= 0.0) {
            return true;
        }

        return (float) $summary['availableCommissionCapacity'] >= $requiredCommission;
    }

    public function assertCanCover(Worker $worker, float $requiredCommission): void
    {
        $summary = $this->capacitySummary($worker);

        if (! (bool) ($summary['isEligibleForNewSessionRequests'] ?? false)) {
            throw new InvalidArgumentException(
                (string) ($summary['financialWarningMessage'] ?? 'Worker is not financially eligible for new session requests.')
            );
        }

        if ($requiredCommission <= 0.0 || (float) $summary['availableCommissionCapacity'] >= $requiredCommission) {
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
