<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum CleaningBookingWorkerAssignmentStatus: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
}
