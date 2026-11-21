<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Illuminate\Http\Request;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

/**
 * Determines when a worker may receive a precise booking destination.
 */
final class CleaningWorkerCustomerDataDisclosurePolicy
{
    public function neighborhoodLabel(CleaningBooking $booking): string
    {
        $candidates = [
            $booking->neighborhood_name,
            data_get($booking->property_details, 'neighborhood'),
            data_get($booking->property_details, 'neighborhood_name'),
            data_get($booking->property_details, 'neighborhoodName'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $name = mb_trim($candidate);
            if ($name !== ''
                && mb_strlen($name) <= 80
                && ! preg_match('/[\r\n]/u', $name)
                && ! preg_match('/[-+]?\d{1,3}\.\d+\s*[,،]\s*[-+]?\d{1,3}\.\d+/u', $name)
            ) {
                return $name;
            }
        }

        return 'المنطقة غير محددة';
    }

    public function mayViewDestination(CleaningBooking $booking, Worker $worker, Request $request): bool
    {
        $routeName = $request->route()?->getName();
        $isCurrentBookingDetail = $routeName === 'api.cleaning-bookings.show'
            || $request->is('api/v1/cleaning/supervised-worker/bookings/*');
        $isTravelActionResponse = in_array($routeName, [
            'api.cleaning-bookings.start-travel',
            'api.cleaning-bookings.sessions.start-travel',
        ], true);

        if (! $isCurrentBookingDetail && ! $isTravelActionResponse) {
            return false;
        }

        $bookingStatus = $booking->status instanceof CleaningBookingStatus
            ? $booking->status->value
            : (string) $booking->status;

        if (! in_array($bookingStatus, [
            CleaningBookingStatus::WorkerAssigned->value,
            CleaningBookingStatus::AwaitingStartVerification->value,
            CleaningBookingStatus::AwaitingWorkerStartConfirmation->value,
            CleaningBookingStatus::InProgress->value,
            CleaningBookingStatus::AwaitingCustomerCompletion->value,
            CleaningBookingStatus::TimeExtensionRequested->value,
        ], true)) {
            return false;
        }

        $sessionParameter = $request->route('cleaning_booking_session')
            ?? $request->route('session')
            ?? $request->query('sessionId');
        if ($sessionParameter !== null) {
            $session = $sessionParameter instanceof CleaningBookingSession
                ? $sessionParameter
                : CleaningBookingSession::query()->find($sessionParameter);

            if (! $session instanceof CleaningBookingSession
                || (int) $session->cleaning_booking_id !== (int) $booking->id) {
                return false;
            }

            $assignment = $session->workerAssignments()
                ->where('worker_id', $worker->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->first();
            $sessionStatus = $session->status instanceof CleaningBookingSessionStatus
                ? $session->status->value
                : (string) $session->status;

            return in_array($sessionStatus, CleaningBookingSessionStatus::activeValues(), true)
                && $assignment instanceof CleaningBookingSessionWorkerAssignment
                && $assignment->started_travel_at !== null
                && $assignment->arrived_at === null;
        }

        if ($booking->sessions()->exists()) {
            return false;
        }

        $assignment = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
            ->first();

        if ($assignment instanceof CleaningBookingWorkerAssignment) {
            return $assignment->started_travel_at !== null
                && $assignment->arrived_at === null;
        }

        if ($booking->workerAssignments()->exists()) {
            return false;
        }

        // Legacy single-worker bookings have no authoritative assignment row.
        return (int) $booking->number_of_workers === 1
            && (int) $booking->worker_id === (int) $worker->id
            && $booking->started_travel_at !== null
            && $booking->arrived_at === null;
    }
}
