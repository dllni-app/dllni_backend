<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Support\Facades\Auth;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingShowController
{
    public function __invoke(CleaningBooking $cleaning_booking): CleaningBookingResource
    {
        $this->ensureWorkerCanViewBooking($cleaning_booking);

        return CleaningBookingResource::make($cleaning_booking->load([
            'customer',
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'addons',
            'billingPolicy',
            'timeWarnings',
            'disputes',
        ]));
    }

    private function ensureWorkerCanViewBooking(CleaningBooking $booking): void
    {
        $worker = Auth::user()?->worker;

        if (! $worker instanceof Worker) {
            return;
        }

        $hasAcceptedAssignment = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();

        if ((int) ($booking->worker_id ?? 0) === (int) $worker->id || $hasAcceptedAssignment) {
            return;
        }

        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status
            : CleaningBookingStatus::tryFrom((string) $booking->status);

        if ($status === CleaningBookingStatus::Pending && ! $booking->isTeamFulfilled()) {
            return;
        }

        abort(409, 'Booking is no longer available for this worker.');
    }
}
