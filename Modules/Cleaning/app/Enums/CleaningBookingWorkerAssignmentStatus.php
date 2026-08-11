<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum CleaningBookingWorkerAssignmentStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case AcceptedWaitingForOrderStart = 'accepted_waiting_for_order_start';
    case AwaitingStartVerification = 'awaiting_start_verification';
    case StartApproved = 'start_approved';
    case InProgress = 'in_progress';
    case AwaitingCustomerCompletion = 'awaiting_customer_completion';
    case TimeExtensionRequested = 'time_extension_requested';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Cancelled = 'cancelled';

    /**
     * These statuses all mean the worker has committed to this booking slot.
     *
     * @return array<int, self>
     */
    public static function acceptedStatuses(): array
    {
        return [
            self::Accepted,
            self::AcceptedWaitingForOrderStart,
            self::AwaitingStartVerification,
            self::StartApproved,
            self::InProgress,
            self::AwaitingCustomerCompletion,
            self::TimeExtensionRequested,
            self::Completed,
        ];
    }

    /**
     * Statuses that still represent active work or a pending customer decision.
     *
     * @return array<int, self>
     */
    public static function activeStatuses(): array
    {
        return [
            self::Accepted,
            self::AcceptedWaitingForOrderStart,
            self::AwaitingStartVerification,
            self::StartApproved,
            self::InProgress,
            self::AwaitingCustomerCompletion,
            self::TimeExtensionRequested,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function acceptedValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::acceptedStatuses(),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::activeStatuses(),
        );
    }
}
