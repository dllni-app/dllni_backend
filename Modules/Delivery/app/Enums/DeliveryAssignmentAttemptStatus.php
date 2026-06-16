<?php

declare(strict_types=1);

namespace Modules\Delivery\Enums;

enum DeliveryAssignmentAttemptStatus: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case TimedOut = 'timed_out';
    case Cancelled = 'cancelled';
}
