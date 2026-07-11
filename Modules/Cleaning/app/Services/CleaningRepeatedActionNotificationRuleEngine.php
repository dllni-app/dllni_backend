<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningRepeatedActionNotificationRuleEngine
{
    public function __construct(
        private readonly CleaningBookingScheduledAtResolver $scheduledAtResolver,
        private readonly CleaningRepeatedWorkerActionRules $workerRules,
        private readonly CleaningRepeatedCustomerActionRules $customerRules,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function dueNotifications(CleaningBooking $booking, CarbonImmutable $now): array
    {
        $scheduledAt = $this->scheduledAtResolver->resolve($booking);
        if (! $scheduledAt instanceof CarbonImmutable) {
            return [];
        }

        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status
            : CleaningBookingStatus::tryFrom((string) $booking->status);
        if (! $status instanceof CleaningBookingStatus) {
            return [];
        }

        $minutesUntilStart = (int) floor($now->diffInMinutes($scheduledAt, false));

        return array_merge(
            $this->workerRules->dueNotifications($booking, $now, $scheduledAt, $status, $minutesUntilStart),
            $this->customerRules->dueNotifications($booking, $now, $scheduledAt, $status, $minutesUntilStart),
        );
    }
}
