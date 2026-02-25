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
}
