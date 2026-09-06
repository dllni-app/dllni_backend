<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Symfony\Component\HttpFoundation\Response;

final class RedactCleaningCustomerContactForUnacceptedWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $workerId = $request->user()?->worker?->id;

        if ($workerId === null || ! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);
        if (! is_array($payload) || ! array_key_exists('data', $payload)) {
            return $response;
        }

        $payload['data'] = $this->redactData($payload['data'], (int) $workerId);
        $response->setData($payload);

        return $response;
    }

    private function redactData(mixed $data, int $workerId): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        if (array_is_list($data)) {
            return array_map(
                fn (mixed $item): mixed => $this->redactBooking($item, $workerId),
                $data,
            );
        }

        return $this->redactBooking($data, $workerId);
    }

    private function redactBooking(mixed $booking, int $workerId): mixed
    {
        if (! is_array($booking) || ! isset($booking['customer']) || ! is_array($booking['customer'])) {
            return $booking;
        }

        if ($this->workerHasAcceptedBooking($booking, $workerId)) {
            return $booking;
        }

        // Do not leak direct contact details for jobs the worker has not accepted.
        // Keep the customer object shape stable for Flutter clients.
        $booking['customer']['phone'] = null;
        $booking['customer']['email'] = null;

        return $booking;
    }

    private function workerHasAcceptedBooking(array $booking, int $workerId): bool
    {
        $myAssignment = $booking['myAssignment'] ?? $booking['my_assignment'] ?? $booking['worker_assignment'] ?? null;
        if (is_array($myAssignment)) {
            $myWorkerId = $myAssignment['workerId'] ?? $myAssignment['worker_id'] ?? $workerId;
            $myStatus = (string) ($myAssignment['status'] ?? '');

            if ((int) $myWorkerId === $workerId
                && in_array($myStatus, CleaningBookingWorkerAssignmentStatus::acceptedValues(), true)) {
                return true;
            }
        }

        $assignments = $booking['workerAssignments'] ?? $booking['worker_assignments'] ?? [];
        if (is_array($assignments)) {
            foreach ($assignments as $assignment) {
                if (! is_array($assignment)) {
                    continue;
                }

                $assignmentWorkerId = $assignment['workerId'] ?? $assignment['worker_id'] ?? null;
                if ((int) $assignmentWorkerId !== $workerId) {
                    continue;
                }

                $status = (string) ($assignment['status'] ?? '');
                if (in_array($status, CleaningBookingWorkerAssignmentStatus::acceptedValues(), true)) {
                    return true;
                }
            }
        }

        // Legacy one-worker bookings can use worker_id without an assignment row.
        // Do not treat worker_id itself as acceptance: pending/dedicated offers can
        // already know the worker. Require a lifecycle state that is only reached
        // after the worker has committed to the booking.
        $directWorkerId = $booking['workerId'] ?? $booking['worker_id'] ?? null;
        if ($directWorkerId === null || (int) $directWorkerId !== $workerId) {
            return false;
        }

        $bookingStatus = (string) (
            $booking['globalStatus']
            ?? $booking['global_status']
            ?? $booking['order_status']
            ?? $booking['status']
            ?? ''
        );

        return in_array($bookingStatus, [
            CleaningBookingStatus::WorkerAssigned->value,
            CleaningBookingStatus::AwaitingStartVerification->value,
            CleaningBookingStatus::AwaitingWorkerStartConfirmation->value,
            CleaningBookingStatus::InProgress->value,
            CleaningBookingStatus::AwaitingCustomerCompletion->value,
            CleaningBookingStatus::TimeExtensionRequested->value,
            CleaningBookingStatus::UnderDispute->value,
            CleaningBookingStatus::Completed->value,
        ], true);
    }
}
