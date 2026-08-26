<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class CleaningBookingMultiDayTeamService
{
    public function __construct(
        private readonly CleaningBookingTeamService $legacyTeamService,
        private readonly WorkerBookingScheduleConflictService $conflicts,
        private readonly WorkerOrderSolvencyService $solvency,
        private readonly DepositService $depositService,
        private readonly CleaningBookingSessionPricingService $sessionPricing,
        private readonly CleaningBookingSessionStatusService $statusService,
    ) {}

    public function acceptWorker(CleaningBooking $booking, Worker $worker, ?array $roomIds = null): CleaningBooking
    {
        if (! $this->requiresFutureSessionReplacementFlow($booking)) {
            return $this->legacyTeamService->acceptWorker($booking, $worker, $roomIds);
        }

        return DB::transaction(function () use ($booking, $worker): CleaningBooking {
            $booking = CleaningBooking::query()
                ->with(['sessions', 'workerAssignments'])
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();
            $worker = Worker::query()->whereKey($worker->id)->lockForUpdate()->firstOrFail();

            if ($booking->remainingSessionsCount() <= 0) {
                throw new InvalidArgumentException('Booking has no remaining event sessions.');
            }
            if ($booking->acceptedWorkerCount() >= max(1, (int) $booking->number_of_workers)) {
                throw new InvalidArgumentException('Booking already has the required number of workers.');
            }
            if ($this->conflicts->hasConflict($worker, $booking)) {
                throw new InvalidArgumentException('Worker is not available for all event days.');
            }
            if (! $this->depositService->isWorkerEligibleForDispatch($worker)) {
                throw new InvalidArgumentException('Worker is not eligible to accept new requests.');
            }

            // Intentionally use the existing solvency source of truth. It is
            // conservative after partial completion because it sees the parent
            // aggregate, which is preferable to accepting a worker beyond their
            // allowed commission capacity.
            $this->solvency->assertWorkerCanAcceptBooking($worker, $booking, null);

            $assignment = CleaningBookingWorkerAssignment::query()
                ->where('cleaning_booking_id', $booking->id)
                ->where('worker_id', $worker->id)
                ->lockForUpdate()
                ->first();

            if ($assignment !== null && in_array(
                (string) ($assignment->status?->value ?? $assignment->status),
                CleaningBookingWorkerAssignmentStatus::acceptedValues(),
                true,
            )) {
                throw new InvalidArgumentException('Worker has already accepted this booking.');
            }

            if ($assignment === null) {
                $assignment = CleaningBookingWorkerAssignment::query()->create([
                    'cleaning_booking_id' => $booking->id,
                    'worker_id' => $worker->id,
                    'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
                    'accepted_at' => now(),
                    'room_count' => 0,
                    'rooms_weight' => 0,
                    'service_share_amount' => 0,
                    'travel_fee' => 0,
                    'admin_margin_amount' => 0,
                    'worker_amount' => 0,
                    'currency' => (string) config('app.currency', 'SYP'),
                ]);
            } else {
                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
                    'accepted_at' => now(),
                    'started_travel_at' => null,
                    'arrived_at' => null,
                    'last_latitude' => null,
                    'last_longitude' => null,
                    'location_updated_at' => null,
                    'start_approved_at' => null,
                    'work_started_at' => null,
                    'work_finished_at' => null,
                    'worker_completion_message' => null,
                ])->saveQuietly();
            }

            $booking = $this->sessionPricing->syncAssignmentsAndRecalculate($booking->fresh(['sessions', 'workerAssignments.worker']));

            return $this->statusService->refreshParent($booking);
        });
    }

    public function releaseWorker(CleaningBooking $booking, Worker $worker, ?string $reason = null): CleaningBooking
    {
        if (! $this->requiresFutureSessionReplacementFlow($booking)) {
            return $this->legacyTeamService->rejectWorker($booking, $worker, $reason);
        }

        return DB::transaction(function () use ($booking, $worker, $reason): CleaningBooking {
            $booking = CleaningBooking::query()
                ->with(['sessions', 'workerAssignments'])
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            $assignment = CleaningBookingWorkerAssignment::query()
                ->where('cleaning_booking_id', $booking->id)
                ->where('worker_id', $worker->id)
                ->lockForUpdate()
                ->first();

            if (! $assignment instanceof CleaningBookingWorkerAssignment) {
                throw new InvalidArgumentException('Worker is not assigned to this booking.');
            }

            $sessionRows = CleaningBookingSessionWorkerAssignment::query()
                ->with('session')
                ->where('cleaning_booking_worker_assignment_id', $assignment->id)
                ->lockForUpdate()
                ->get();

            foreach ($sessionRows as $row) {
                if ($row->session?->status === CleaningBookingSessionStatus::Completed
                    || ($row->status?->value ?? $row->status) === CleaningBookingWorkerAssignmentStatus::Completed->value) {
                    continue;
                }

                $row->forceFill([
                    'status' => filled($reason)
                        ? CleaningBookingWorkerAssignmentStatus::Rejected
                        : CleaningBookingWorkerAssignmentStatus::Withdrawn,
                ])->saveQuietly();
            }

            $completedRows = $sessionRows->filter(
                fn (CleaningBookingSessionWorkerAssignment $row): bool => $row->session?->status === CleaningBookingSessionStatus::Completed
                    || ($row->status?->value ?? $row->status) === CleaningBookingWorkerAssignmentStatus::Completed->value,
            );

            $assignment->forceFill([
                'status' => filled($reason)
                    ? CleaningBookingWorkerAssignmentStatus::Rejected
                    : CleaningBookingWorkerAssignmentStatus::Withdrawn,
                // Preserve only executed historical amounts on the parent row.
                'service_share_amount' => round((float) $completedRows->sum('service_share_amount'), 2),
                'travel_fee' => round((float) $completedRows->sum('travel_fee'), 2),
                'admin_margin_amount' => round((float) $completedRows->sum('admin_margin_amount'), 2),
                'worker_amount' => round((float) $completedRows->sum('worker_amount'), 2),
            ])->saveQuietly();

            $booking->rejections()->updateOrCreate(
                ['worker_id' => $worker->id],
                ['reason' => $reason, 'rejected_at' => now()],
            );

            $booking = $this->sessionPricing->syncAssignmentsAndRecalculate($booking->fresh(['sessions', 'workerAssignments.worker']));

            return $this->statusService->refreshParent($booking);
        });
    }

    private function requiresFutureSessionReplacementFlow(CleaningBooking $booking): bool
    {
        return $booking->isEventAssistanceBooking()
            && $booking->sessions()->exists()
            && in_array($booking->status, [
                CleaningBookingStatus::PartiallyCompleted,
            ], true);
    }
}
