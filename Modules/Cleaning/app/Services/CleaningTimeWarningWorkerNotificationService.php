<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Models\EventBooking;

final class CleaningTimeWarningWorkerNotificationService
{
    public function __construct(
        private readonly CleaningLifecycleNotificationService $lifecycleNotifications,
    ) {}

    public function accepted(CleaningTimeWarning $warning, ?string $fromStatus = null): void
    {
        $this->send(
            warning: $warning,
            canonicalType: 'cleaning.booking.time_extension_accepted',
            action: 'time_extension_accepted',
            fromStatus: $fromStatus,
        );
    }

    public function declined(CleaningTimeWarning $warning, ?string $fromStatus = null, ?string $message = null): void
    {
        $this->send(
            warning: $warning,
            canonicalType: 'cleaning.booking.time_extension_rejected',
            action: 'time_extension_rejected',
            fromStatus: $fromStatus,
            message: $message,
        );
    }

    private function send(
        CleaningTimeWarning $warning,
        string $canonicalType,
        string $action,
        ?string $fromStatus = null,
        ?string $message = null,
    ): void {
        $booking = $warning->relationLoaded('booking') ? $warning->booking : $warning->booking()->first();

        if (! $booking instanceof CleaningBooking && ! $booking instanceof EventBooking) {
            return;
        }

        $assignment = $booking instanceof CleaningBooking
            ? $this->warningAssignment($warning, $booking)
            : null;
        $workerId = $warning->worker_id !== null
            ? (int) $warning->worker_id
            : ($booking instanceof CleaningBooking ? $booking->worker_id : null);
        $assignmentId = $assignment?->id;
        $occurredAt = $warning->worker_responded_at?->toIso8601String() ?? now()->toIso8601String();
        $bookingType = $booking instanceof EventBooking ? 'event_booking' : 'cleaning_booking';
        $extraData = [
            'warningId' => $warning->id,
            'assignmentId' => $assignmentId,
            'workerId' => $workerId,
            'message' => $message,
            'workerRejectMessage' => $message,
            'worker_reject_message' => $message,
            'bookingType' => $bookingType,
            'booking_type' => $bookingType,
            'propertyType' => $booking instanceof CleaningBooking ? $booking->property_type : null,
            'property_type' => $booking instanceof CleaningBooking ? $booking->property_type : null,
        ];
        $templateContext = [
            'warningId' => $warning->id,
            'assignmentId' => $assignmentId,
            'bookingType' => $bookingType,
        ];

        if ($workerId !== null) {
            $this->lifecycleNotifications->notifyWorkerById(
                booking: $booking,
                workerId: (int) $workerId,
                canonicalType: $canonicalType,
                action: $action,
                actorRole: 'worker',
                fromStatus: $fromStatus,
                occurredAt: $occurredAt,
                extraData: $extraData,
                templateContext: $templateContext,
            );
        } elseif ($booking instanceof CleaningBooking) {
            $this->lifecycleNotifications->notifyWorker(
                booking: $booking,
                canonicalType: $canonicalType,
                action: $action,
                actorRole: 'worker',
                fromStatus: $fromStatus,
                occurredAt: $occurredAt,
                extraData: $extraData,
                templateContext: $templateContext,
            );
        }

        $this->lifecycleNotifications->notifyCustomer(
            booking: $booking,
            canonicalType: $canonicalType,
            action: $action,
            actorRole: 'worker',
            fromStatus: $fromStatus,
            occurredAt: $occurredAt,
            extraData: $extraData,
            templateContext: $templateContext,
        );
    }

    private function warningAssignment(CleaningTimeWarning $warning, CleaningBooking $booking): ?CleaningBookingWorkerAssignment
    {
        if ($warning->worker_id === null) {
            return null;
        }

        return $booking->workerAssignments()
            ->where('worker_id', $warning->worker_id)
            ->first();
    }
}
