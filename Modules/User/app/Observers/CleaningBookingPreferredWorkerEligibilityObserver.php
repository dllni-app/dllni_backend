<?php

declare(strict_types=1);

namespace Modules\User\Observers;

use App\Models\Worker;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\DepositService;

final class CleaningBookingPreferredWorkerEligibilityObserver
{
    public function __construct(
        private readonly DepositService $depositService,
    ) {}

    public function creating(CleaningBooking $booking): void
    {
        $this->validatePreferredWorker($booking);
    }

    public function updating(CleaningBooking $booking): void
    {
        if (! $booking->isDirty(['preferred_worker_id', 'assignment_mode', 'neighborhood_id'])) {
            return;
        }

        $this->validatePreferredWorker($booking);
    }

    private function validatePreferredWorker(CleaningBooking $booking): void
    {
        if ($booking->resolvedAssignmentMode() !== 'preferred_worker' || $booking->preferred_worker_id === null) {
            return;
        }

        $worker = Worker::query()
            ->with(['user', 'deposit'])
            ->find((int) $booking->preferred_worker_id);

        if (
            ! $worker instanceof Worker
            || $worker->user === null
            || ! (bool) $worker->user->is_active
            || ! $this->depositService->isWorkerEligibleForDispatch($worker)
        ) {
            throw ValidationException::withMessages([
                'preferredWorkerId' => ['Selected worker cannot receive new cleaning requests.'],
            ]);
        }

        if (
            $booking->neighborhood_id !== null
            && ! $worker->hasActiveCoverageForNeighborhood((int) $booking->neighborhood_id)
        ) {
            throw ValidationException::withMessages([
                'preferredWorkerId' => ['Selected worker does not cover the selected neighborhood.'],
            ]);
        }
    }
}
