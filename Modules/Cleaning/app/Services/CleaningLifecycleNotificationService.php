<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\BookingLifecycleNotification;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class CleaningLifecycleNotificationService
{
    /**
     * @param  array<string, mixed>  $extraData
     * @param  array<string, scalar|null>  $templateContext
     */
    public function notifyCustomer(
        CleaningBooking $booking,
        string $canonicalType,
        string $action,
        string $actorRole,
        ?string $fromStatus = null,
        ?string $deepLinkTarget = null,
        ?string $occurredAt = null,
        array $extraData = [],
        array $templateContext = [],
    ): void {
        $customer = $booking->customer;

        if (! $customer instanceof User) {
            return;
        }

        $customer->notify(new BookingLifecycleNotification(
            booking: $booking,
            canonicalType: $canonicalType,
            actorRole: $actorRole,
            targetRole: 'customer',
            fromStatus: $fromStatus,
            action: $action,
            deepLinkTarget: $deepLinkTarget ?? 'cleaning_order_details',
            occurredAt: $occurredAt,
            extraData: $extraData,
            templateContext: $templateContext,
        ));
    }

    /**
     * @param  array<string, mixed>  $extraData
     * @param  array<string, scalar|null>  $templateContext
     */
    public function notifyWorker(
        CleaningBooking $booking,
        string $canonicalType,
        string $action,
        string $actorRole,
        ?string $fromStatus = null,
        ?string $deepLinkTarget = null,
        ?string $occurredAt = null,
        array $extraData = [],
        array $templateContext = [],
    ): void {
        $workerUser = $booking->worker?->user;

        if (! $workerUser instanceof User) {
            return;
        }

        $this->notifyWorkerUser(
            workerUser: $workerUser,
            booking: $booking,
            canonicalType: $canonicalType,
            action: $action,
            actorRole: $actorRole,
            fromStatus: $fromStatus,
            deepLinkTarget: $deepLinkTarget,
            occurredAt: $occurredAt,
            extraData: $extraData,
            templateContext: $templateContext,
        );
    }

    /**
     * @param  array<string, mixed>  $extraData
     * @param  array<string, scalar|null>  $templateContext
     */
    public function notifyWorkerById(
        CleaningBooking $booking,
        int $workerId,
        string $canonicalType,
        string $action,
        string $actorRole,
        ?string $fromStatus = null,
        ?string $deepLinkTarget = null,
        ?string $occurredAt = null,
        array $extraData = [],
        array $templateContext = [],
    ): void {
        $worker = Worker::query()->with('user')->find($workerId);
        $workerUser = $worker?->user;

        if (! $workerUser instanceof User) {
            return;
        }

        $extraData = array_merge(['workerId' => $workerId], $extraData);
        $templateContext = array_merge(['workerId' => $workerId], $templateContext);

        $this->notifyWorkerUser(
            workerUser: $workerUser,
            booking: $booking,
            canonicalType: $canonicalType,
            action: $action,
            actorRole: $actorRole,
            fromStatus: $fromStatus,
            deepLinkTarget: $deepLinkTarget,
            occurredAt: $occurredAt,
            extraData: $extraData,
            templateContext: $templateContext,
        );
    }

    /**
     * @param  array<string, mixed>  $extraData
     * @param  array<string, scalar|null>  $templateContext
     */
    public function notifyWorkerAssignment(
        CleaningBooking $booking,
        CleaningBookingWorkerAssignment $assignment,
        string $canonicalType,
        string $action,
        string $actorRole,
        ?string $fromStatus = null,
        ?string $deepLinkTarget = null,
        ?string $occurredAt = null,
        array $extraData = [],
        array $templateContext = [],
    ): void {
        $this->notifyWorkerById(
            booking: $booking,
            workerId: (int) $assignment->worker_id,
            canonicalType: $canonicalType,
            action: $action,
            actorRole: $actorRole,
            fromStatus: $fromStatus,
            deepLinkTarget: $deepLinkTarget,
            occurredAt: $occurredAt,
            extraData: array_merge(['assignmentId' => $assignment->id], $extraData),
            templateContext: array_merge(['assignmentId' => $assignment->id], $templateContext),
        );
    }

    /**
     * @param  array<string, mixed>  $extraData
     * @param  array<string, scalar|null>  $templateContext
     */
    private function notifyWorkerUser(
        User $workerUser,
        CleaningBooking $booking,
        string $canonicalType,
        string $action,
        string $actorRole,
        ?string $fromStatus = null,
        ?string $deepLinkTarget = null,
        ?string $occurredAt = null,
        array $extraData = [],
        array $templateContext = [],
    ): void {
        $workerUser->notify(new BookingLifecycleNotification(
            booking: $booking,
            canonicalType: $canonicalType,
            actorRole: $actorRole,
            targetRole: 'worker',
            fromStatus: $fromStatus,
            action: $action,
            deepLinkTarget: $deepLinkTarget ?? 'cleaning_booking_details',
            occurredAt: $occurredAt,
            extraData: $extraData,
            templateContext: $templateContext,
        ));
    }
}
