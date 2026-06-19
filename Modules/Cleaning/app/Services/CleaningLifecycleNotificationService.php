<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use App\Notifications\Cleaning\BookingLifecycleNotification;
use Modules\Cleaning\Models\CleaningBooking;

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
