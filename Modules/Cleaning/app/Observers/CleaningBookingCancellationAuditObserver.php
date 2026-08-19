<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningLifecycleNotificationService;

final class CleaningBookingCancellationAuditObserver
{
    public function updating(CleaningBooking $booking): void
    {
        if (! $booking->isDirty('status') || ! $this->isCancelled($booking)) {
            return;
        }

        $cancelledAt = $booking->cancelled_at ?? now();
        $booking->cancelled_at = $cancelledAt;

        $authenticatedWorker = Auth::user()?->worker;
        if ($authenticatedWorker instanceof Worker) {
            $booking->cancelled_by_role = 'worker';
            $booking->cancelled_by_worker_id = $authenticatedWorker->id;
        }

        // Read directly from the hydrated attributes so cancellation still works when
        // strict missing-attribute protection is enabled and this column was omitted
        // from a partial select. The observer will populate the audit value on save.
        if (($booking->getAttributes()['cancellation_offset_minutes'] ?? null) === null) {
            $scheduledAt = $this->scheduledStart($booking);
            $booking->cancellation_offset_minutes = $scheduledAt?->diffInMinutes($cancelledAt, false) * -1;
        }
    }

    public function updated(CleaningBooking $booking): void
    {
        if (! $booking->wasChanged('status') || ! $this->isCancelled($booking)) {
            return;
        }

        $assignments = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->get();

        $linkedWorkerIds = $this->linkedWorkerIds($booking, $assignments);

        foreach ($assignments as $assignment) {
            $previousStatus = $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
                ? $assignment->status->value
                : (string) $assignment->status;

            $assignment->forceFill([
                'status_before_booking_cancellation' => $assignment->status_before_booking_cancellation ?? $previousStatus,
                'booking_cancelled_at' => $assignment->booking_cancelled_at ?? $booking->cancelled_at,
                'cancelled_by_this_worker' => (int) $assignment->worker_id === (int) ($booking->getAttributes()['cancelled_by_worker_id'] ?? 0),
                'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
            ])->saveQuietly();
        }

        $this->notifyLinkedWorkers($booking, $linkedWorkerIds);
    }

    /**
     * @param Collection<int, CleaningBookingWorkerAssignment> $assignments
     * @return array<int, int>
     */
    private function linkedWorkerIds(CleaningBooking $booking, Collection $assignments): array
    {
        $workerIds = $assignments
            ->filter(static function (CleaningBookingWorkerAssignment $assignment): bool {
                $status = $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
                    ? $assignment->status->value
                    : (string) $assignment->status;

                return in_array($status, CleaningBookingWorkerAssignmentStatus::acceptedValues(), true);
            })
            ->pluck('worker_id')
            ->map(static fn (mixed $workerId): int => (int) $workerId)
            ->all();

        if ($booking->worker_id !== null) {
            $workerIds[] = (int) $booking->worker_id;
        }

        return array_values(array_unique(array_filter(
            $workerIds,
            static fn (int $workerId): bool => $workerId > 0,
        )));
    }

    /** @param array<int, int> $workerIds */
    private function notifyLinkedWorkers(CleaningBooking $booking, array $workerIds): void
    {
        if ($workerIds === []) {
            return;
        }

        $actorRole = $this->cancellationActorRole($booking);
        $cancelledByWorkerId = (int) ($booking->getAttributes()['cancelled_by_worker_id'] ?? 0);
        $primaryWorkerId = $booking->worker_id !== null ? (int) $booking->worker_id : null;
        $customerPrimaryAlreadyNotified = $actorRole === 'customer' && $primaryWorkerId !== null;
        $fromStatus = (string) ($booking->getRawOriginal('status') ?? '');
        $notificationService = app(CleaningLifecycleNotificationService::class);

        foreach ($workerIds as $workerId) {
            // Customer cancellation endpoints already notify the primary legacy worker.
            // The observer fills the gap for accepted multi-worker assignments while
            // preventing a duplicate notification to that primary worker.
            if ($customerPrimaryAlreadyNotified && $workerId === $primaryWorkerId) {
                continue;
            }

            // A worker who cancelled the order does not need a notification about
            // their own action, but other accepted workers on the same order do.
            if ($actorRole === 'worker' && $workerId === $cancelledByWorkerId) {
                continue;
            }

            $notificationService->notifyWorkerById(
                booking: $booking,
                workerId: $workerId,
                canonicalType: 'cleaning.booking.order_cancelled',
                action: $actorRole.'_cancelled',
                actorRole: $actorRole,
                fromStatus: $fromStatus !== '' ? $fromStatus : null,
                occurredAt: $booking->cancelled_at?->toIso8601String() ?? $booking->updated_at?->toIso8601String(),
                extraData: [
                    'cancellationReason' => $booking->cancellation_reason,
                    'cancellation_reason' => $booking->cancellation_reason,
                ],
            );
        }
    }

    private function cancellationActorRole(CleaningBooking $booking): string
    {
        $storedRole = mb_strtolower(mb_trim((string) ($booking->getAttributes()['cancelled_by_role'] ?? '')));

        if (in_array($storedRole, ['customer', 'worker', 'admin', 'system'], true)) {
            return $storedRole;
        }

        $authenticatedWorker = Auth::user()?->worker;
        if ($authenticatedWorker instanceof Worker) {
            return 'worker';
        }

        $authenticatedUserId = Auth::id();
        if ($authenticatedUserId !== null && (int) $booking->customer_id === (int) $authenticatedUserId) {
            return 'customer';
        }

        return $authenticatedUserId !== null ? 'admin' : 'system';
    }

    private function isCancelled(CleaningBooking $booking): bool
    {
        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status
            : CleaningBookingStatus::tryFrom((string) $booking->status);

        return $status === CleaningBookingStatus::Cancelled;
    }

    private function scheduledStart(CleaningBooking $booking): ?Carbon
    {
        $attributes = $booking->getAttributes();
        $scheduledDate = $attributes['scheduled_date'] ?? null;
        $scheduledTime = $attributes['scheduled_time'] ?? null;

        if ($scheduledDate === null || blank($scheduledTime)) {
            return null;
        }

        try {
            $date = $scheduledDate instanceof \DateTimeInterface
                ? $scheduledDate->format('Y-m-d')
                : mb_substr((string) $scheduledDate, 0, 10);

            return Carbon::parse($date.' '.(string) $scheduledTime);
        } catch (\Throwable) {
            return null;
        }
    }
}
