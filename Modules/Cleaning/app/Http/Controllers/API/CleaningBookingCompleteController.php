<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Http\Requests\CleaningBookingCompleteRequest;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingService;
use Throwable;

final class CleaningBookingCompleteController
{
    public function __construct(private readonly CleaningBookingService $cleaningBookingService) {}

    /** @throws Throwable */
    public function __invoke(CleaningBookingCompleteRequest $request, CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking);

        try {
            $booking = $this->shouldUseTeamCompletionFlow($cleaning_booking)
                ? $this->completeTeamWorker($cleaning_booking, $request->completionMessage())
                : $this->cleaningBookingService->complete(
                    $cleaning_booking,
                    $request->completionMessage(),
                );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        $booking = $this->loadBookingDetails($booking);
        $finishedServices = $request->finishedCleaningServices();
        $finishedRooms = $request->finishedPropertyRooms();

        if ($booking->status === CleaningBookingStatus::AwaitingCustomerCompletion) {
            $booking->forceFill([
                'worker_finished_cleaning_services' => $finishedServices !== [] ? $finishedServices : $this->inferFinishedServices($booking),
                'worker_finished_property_rooms' => $finishedRooms !== [] ? $finishedRooms : $this->inferFinishedRooms($booking),
            ])->save();
        }

        return CleaningBookingResource::make($this->loadBookingDetails($booking));
    }

    private function ensureWorkerCanActOnBooking(CleaningBooking $booking): void
    {
        $worker = Auth::user()?->worker;

        if (! $worker instanceof Worker) {
            abort(403, 'User must have an associated worker.');
        }

        $hasWorkerAssignment = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();

        if ($booking->worker_id !== null && $booking->worker_id !== $worker->id && ! $hasWorkerAssignment) {
            abort(403, 'Booking is assigned to another worker.');
        }

        if ($booking->worker_id === null && ! $hasWorkerAssignment) {
            abort(403, 'Booking must be assigned to worker for this action.');
        }
    }

    private function shouldUseTeamCompletionFlow(CleaningBooking $booking): bool
    {
        return max(1, (int) ($booking->number_of_workers ?? 1)) > 1
            && $booking->workerAssignments()->exists();
    }

    private function completeTeamWorker(CleaningBooking $booking, ?string $completionMessage = null): CleaningBooking
    {
        $completionMessage = is_string($completionMessage) && mb_trim($completionMessage) !== ''
            ? mb_trim($completionMessage)
            : null;

        $updated = DB::transaction(function () use ($booking, $completionMessage): CleaningBooking {
            $worker = Auth::user()?->worker;
            if (! $worker instanceof Worker) {
                throw new InvalidArgumentException('User must have an associated worker.');
            }

            $lockedBooking = CleaningBooking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedBooking->status, [
                CleaningBookingStatus::AwaitingWorkerStartConfirmation,
                CleaningBookingStatus::InProgress,
            ], true)) {
                throw new InvalidArgumentException('Booking must be active before this worker can mark completion.');
            }

            $assignment = CleaningBookingWorkerAssignment::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->where('worker_id', $worker->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->lockForUpdate()
                ->first();

            if (! $assignment instanceof CleaningBookingWorkerAssignment) {
                throw new InvalidArgumentException('Worker must accept the booking before marking completion.');
            }

            $assignmentStatus = $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
                ? $assignment->status->value
                : (string) $assignment->status;

            if (! in_array($assignmentStatus, [
                CleaningBookingWorkerAssignmentStatus::InProgress->value,
                CleaningBookingWorkerAssignmentStatus::StartApproved->value,
            ], true)) {
                throw new InvalidArgumentException('Worker assignment must be in progress to mark completion.');
            }

            $finishedAt = now();
            $assignment->forceFill([
                'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion,
                'work_finished_at' => $finishedAt,
                'worker_completion_message' => $completionMessage,
            ])->save();

            $requiredWorkers = max(1, (int) ($lockedBooking->number_of_workers ?? 1));
            $finishedWorkers = CleaningBookingWorkerAssignment::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->whereIn('status', [
                    CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
                    CleaningBookingWorkerAssignmentStatus::Completed->value,
                ])
                ->lockForUpdate()
                ->count();

            if ($finishedWorkers >= $requiredWorkers) {
                $lockedBooking->forceFill([
                    'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
                    'work_finished_at' => $finishedAt,
                    'worker_completion_message' => $completionMessage,
                    'customer_completion_rejection_message' => null,
                    'completion_rejected_at' => null,
                ])->save();
            }

            return $this->freshBooking($lockedBooking);
        });

        $this->dispatchTrackingUpdate($updated);

        return $updated;
    }

    private function loadBookingDetails(CleaningBooking $booking): CleaningBooking
    {
        return $booking->load([
            'customer',
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'addons',
            'billingPolicy',
            'timeWarnings',
            'disputes',
        ]);
    }

    private function freshBooking(CleaningBooking $booking): CleaningBooking
    {
        return $booking->fresh([
            'customer',
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'addons',
            'billingPolicy',
            'timeWarnings',
            'disputes',
        ]);
    }

    private function dispatchTrackingUpdate(CleaningBooking $booking): void
    {
        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status->value
            : (string) $booking->status;

        BroadcastAfterResponse::send(new CleaningBookingTrackingUpdated($booking->id, [
            'cleaningBookingId' => $booking->id,
            'status' => $status,
            'statusLabel' => $booking->status instanceof CleaningBookingStatus ? $booking->status->label() : null,
            'workerId' => $booking->worker_id,
            'assignmentMode' => $booking->resolvedAssignmentMode(),
            'requiredWorkers' => max(1, (int) ($booking->number_of_workers ?? 1)),
            'acceptedWorkers' => $booking->acceptedWorkerCount(),
            'remainingWorkers' => $booking->remainingWorkerCount(),
            'startApprovedWorkers' => $booking->startApprovedWorkerCount(),
            'notStartApprovedWorkers' => $booking->notStartApprovedWorkerCount(),
            'isTeamFulfilled' => $booking->isTeamFulfilled(),
            'startedTravelAt' => $booking->started_travel_at?->toIso8601String(),
            'arrivedAt' => $booking->arrived_at?->toIso8601String(),
            'workStartedAt' => $booking->work_started_at?->toIso8601String(),
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'timerStoppedAt' => $booking->work_finished_at?->toIso8601String(),
            'isTimerRunning' => $status === CleaningBookingStatus::InProgress->value,
            'workerCompletionMessage' => $booking->worker_completion_message,
            'customerCompletionRejectionMessage' => $booking->customer_completion_rejection_message,
            'completionRejectedAt' => $booking->completion_rejected_at?->toIso8601String(),
            'customerConfirmedAt' => $booking->customer_confirmed_at?->toIso8601String(),
            'cancelledAt' => $booking->cancelled_at?->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
        ]));
    }

    /** @return array<int, array<string, mixed>> */
    private function inferFinishedServices(CleaningBooking $booking): array
    {
        $services = [];

        if (is_array($booking->cleaning_services)) {
            foreach ($booking->cleaning_services as $index => $service) {
                $name = is_string($service) ? trim($service) : '';
                if ($name !== '') {
                    $services[] = ['id' => null, 'name' => $name, 'label' => $name, 'sort' => $index];
                }
            }
        }

        foreach ($booking->addons as $addon) {
            $name = trim((string) ($addon->name ?? $addon->title ?? ''));
            if ($name !== '') {
                $services[] = ['id' => $addon->id, 'name' => $name, 'label' => $name];
            }
        }

        return array_values($services);
    }

    /** @return array<int, array<string, mixed>> */
    private function inferFinishedRooms(CleaningBooking $booking): array
    {
        return $booking->rooms
            ->map(static fn (CleaningBookingRoom $room): array => [
                'id' => $room->id,
                'roomKey' => $room->room_key,
                'roomType' => $room->room_type,
                'displayLabel' => $room->display_label,
            ])
            ->values()
            ->all();
    }
}
