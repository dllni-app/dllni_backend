<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

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

        if ($booking->cancellation_offset_minutes === null) {
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

        foreach ($assignments as $assignment) {
            $previousStatus = $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
                ? $assignment->status->value
                : (string) $assignment->status;

            $assignment->forceFill([
                'status_before_booking_cancellation' => $assignment->status_before_booking_cancellation ?? $previousStatus,
                'booking_cancelled_at' => $assignment->booking_cancelled_at ?? $booking->cancelled_at,
                'cancelled_by_this_worker' => (int) $assignment->worker_id === (int) ($booking->cancelled_by_worker_id ?? 0),
                'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
            ])->saveQuietly();
        }
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
        if ($booking->scheduled_date === null || blank($booking->scheduled_time)) {
            return null;
        }

        try {
            $date = $booking->scheduled_date instanceof \DateTimeInterface
                ? $booking->scheduled_date->format('Y-m-d')
                : mb_substr((string) $booking->scheduled_date, 0, 10);

            return Carbon::parse($date.' '.(string) $booking->scheduled_time);
        } catch (\Throwable) {
            return null;
        }
    }
}
