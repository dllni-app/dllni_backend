<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use App\Models\Worker;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Throwable;

final class CleaningBookingCancellationService
{
    public function prepareCancellation(CleaningBooking $booking): void
    {
        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status
            : CleaningBookingStatus::tryFrom((string) $booking->status);

        if ($status !== CleaningBookingStatus::Cancelled) {
            return;
        }

        $cancelledAt = $booking->cancelled_at instanceof CarbonInterface
            ? Carbon::instance($booking->cancelled_at)
            : now();

        $booking->cancelled_at ??= $cancelledAt;

        $this->resolveActor($booking);

        if ($booking->cancellation_offset_minutes === null) {
            $booking->cancellation_offset_minutes = $this->calculateOffsetMinutes($booking, $cancelledAt);
        }

        $this->snapshotAssignments($booking, $cancelledAt);
    }

    private function resolveActor(CleaningBooking $booking): void
    {
        $authenticated = Auth::user();
        $user = $authenticated instanceof User ? $authenticated : null;
        $worker = $user?->worker;
        $role = is_string($booking->cancelled_by_role) && $booking->cancelled_by_role !== ''
            ? $booking->cancelled_by_role
            : ($worker instanceof Worker ? 'worker' : ($user instanceof User ? 'customer' : 'system'));

        $booking->cancelled_by_role = $role;

        if ($role === 'worker' && $worker instanceof Worker) {
            $booking->cancelled_by_user_id = $user?->id;
            $booking->cancelled_by_worker_id = $worker->id;

            return;
        }

        if ($role === 'customer' && $user instanceof User) {
            $booking->cancelled_by_user_id = $user->id;
            $booking->cancelled_by_worker_id = null;
        }
    }

    private function calculateOffsetMinutes(CleaningBooking $booking, CarbonInterface $cancelledAt): ?int
    {
        if ($booking->scheduled_date === null || $booking->scheduled_time === null) {
            return null;
        }

        try {
            $date = $booking->scheduled_date instanceof CarbonInterface
                ? $booking->scheduled_date->toDateString()
                : mb_substr((string) $booking->scheduled_date, 0, 10);
            $scheduledAt = Carbon::parse($date.' '.(string) $booking->scheduled_time, config('app.timezone'));

            return (int) round(($scheduledAt->getTimestamp() - $cancelledAt->getTimestamp()) / 60);
        } catch (Throwable) {
            return null;
        }
    }

    private function snapshotAssignments(CleaningBooking $booking, CarbonInterface $cancelledAt): void
    {
        $assignments = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->lockForUpdate()
            ->get();

        foreach ($assignments as $assignment) {
            $currentStatus = $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
                ? $assignment->status->value
                : (string) $assignment->status;

            $updates = [
                'status_before_booking_cancellation' => $assignment->status_before_booking_cancellation ?? $currentStatus,
                'booking_cancelled_at' => $assignment->booking_cancelled_at ?? $cancelledAt,
                'cancelled_by_this_worker' => $booking->cancelled_by_worker_id !== null
                    && (int) $assignment->worker_id === (int) $booking->cancelled_by_worker_id,
            ];

            if (in_array($currentStatus, CleaningBookingWorkerAssignmentStatus::activeValues(), true)) {
                $updates['status'] = CleaningBookingWorkerAssignmentStatus::Cancelled;
            }

            $assignment->forceFill($updates)->save();
        }
    }
}
