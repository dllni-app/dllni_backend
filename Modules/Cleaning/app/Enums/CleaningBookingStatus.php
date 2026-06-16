<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum CleaningBookingStatus: string
{
    case Pending = 'pending';
    case WorkerAssigned = 'worker_assigned';
    case AwaitingStartVerification = 'awaiting_start_verification';
    case AwaitingWorkerStartConfirmation = 'awaiting_worker_start_confirmation';
    case InProgress = 'in_progress';
    case AwaitingCustomerCompletion = 'awaiting_customer_completion';
    case TimeExtensionRequested = 'time_extension_requested';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('cleaning_admin.enums.cleaning_booking_status.'.$this->value);
    }
}
