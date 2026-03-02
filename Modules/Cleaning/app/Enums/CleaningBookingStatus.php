<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum CleaningBookingStatus: string
{
    case Pending = 'pending';
    case WorkerAssigned = 'worker_assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('cleaning_admin.enums.cleaning_booking_status.'.$this->value);
    }
}
