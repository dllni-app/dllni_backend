<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class CleaningBookingSessionWorkerAssignmentObserver
{
    public function saved(CleaningBookingSessionWorkerAssignment $assignment): void
    {
        $parent = $assignment->parentAssignment()->first();
        if ($parent === null) {
            return;
        }

        $projection = CleaningBookingSessionWorkerAssignment::query()
            ->where('cleaning_booking_worker_assignment_id', $parent->id)
            ->whereHas('session', fn ($query) => $query->whereNotIn('status', [
                CleaningBookingSessionStatus::Completed->value,
                CleaningBookingSessionStatus::Cancelled->value,
            ]))
            ->with('session')
            ->get()
            ->sortBy(fn (CleaningBookingSessionWorkerAssignment $row): string => ($row->session?->scheduled_date?->toDateString() ?? '').' '.(string) $row->session?->scheduled_time)
            ->first();

        if ($projection === null) {
            $projection = CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_worker_assignment_id', $parent->id)
                ->with('session')
                ->get()
                ->sortByDesc(fn (CleaningBookingSessionWorkerAssignment $row): string => ($row->session?->scheduled_date?->toDateString() ?? '').' '.(string) $row->session?->scheduled_time)
                ->first();
        }

        if ($projection === null) {
            return;
        }

        $parent->forceFill([
            'started_travel_at' => $projection->started_travel_at,
            'arrived_at' => $projection->arrived_at,
            'last_latitude' => $projection->last_latitude,
            'last_longitude' => $projection->last_longitude,
            'location_updated_at' => $projection->location_updated_at,
            'start_approved_at' => $projection->start_approved_at,
            'work_started_at' => $projection->work_started_at,
            'work_finished_at' => $projection->work_finished_at,
            'worker_completion_message' => $projection->worker_completion_message,
        ])->saveQuietly();
    }
}
