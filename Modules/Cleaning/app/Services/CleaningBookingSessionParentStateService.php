<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

final class CleaningBookingSessionParentStateService
{
    public function refresh(CleaningBooking $booking): CleaningBooking
    {
        return DB::transaction(function () use ($booking): CleaningBooking {
            $sessions = CleaningBookingSession::query()
                ->where('cleaning_booking_id', $booking->id)
                ->lockForUpdate()
                ->get();

            $lockedBooking = CleaningBooking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($sessions->isEmpty()) {
                return $lockedBooking;
            }

            $statuses = $sessions->map(static function (CleaningBookingSession $session): string {
                return $session->status instanceof CleaningBookingSessionStatus
                    ? $session->status->value
                    : (string) $session->status;
            });

            if ($statuses->every(static fn (string $status): bool => in_array(
                $status,
                CleaningBookingSessionStatus::terminalValues(),
                true,
            ))) {
                $status = $statuses->contains(CleaningBookingSessionStatus::Completed->value)
                    ? CleaningBookingStatus::Completed
                    : CleaningBookingStatus::Cancelled;
            } elseif ($statuses->contains(CleaningBookingSessionStatus::UnderDispute->value)) {
                $status = CleaningBookingStatus::UnderDispute;
            } elseif ($statuses->contains(CleaningBookingSessionStatus::TimeExtensionRequested->value)) {
                $status = CleaningBookingStatus::TimeExtensionRequested;
            } elseif ($statuses->contains(CleaningBookingSessionStatus::AwaitingCustomerCompletion->value)) {
                $status = CleaningBookingStatus::AwaitingCustomerCompletion;
            } elseif ($statuses->contains(CleaningBookingSessionStatus::InProgress->value)) {
                $status = CleaningBookingStatus::InProgress;
            } elseif ($statuses->contains(CleaningBookingSessionStatus::AwaitingStartVerification->value)) {
                $status = CleaningBookingStatus::AwaitingStartVerification;
            } elseif ($statuses->contains(CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation->value)) {
                $status = CleaningBookingStatus::AwaitingWorkerStartConfirmation;
            } elseif ($statuses->contains(CleaningBookingSessionStatus::WorkerAssigned->value)) {
                $status = CleaningBookingStatus::WorkerAssigned;
            } else {
                $status = CleaningBookingStatus::Pending;
            }

            $updates = ['status' => $status];
            if ($status === CleaningBookingStatus::Completed && $lockedBooking->work_finished_at === null) {
                $updates['work_finished_at'] = now();
            }

            if ($status === CleaningBookingStatus::Cancelled && $lockedBooking->cancelled_at === null) {
                $updates['cancelled_at'] = now();
                $updates['cancelled_by_role'] = 'system';
                $updates['cancellation_reason'] = 'All execution sessions were cancelled or skipped.';
            }

            $lockedBooking->forceFill($updates)->save();

            return $lockedBooking->fresh() ?? $lockedBooking;
        });
    }
}
