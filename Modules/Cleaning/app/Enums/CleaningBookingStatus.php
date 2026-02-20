<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum CleaningBookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case WorkerAssigned = 'worker_assigned';
    case WorkerOnTheWay = 'worker_on_the_way';
    case WorkerArrived = 'worker_arrived';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
