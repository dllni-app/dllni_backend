<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum EventBookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case TeamAssigned = 'team_assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('cleaning_admin.enums.event_booking_status.'.$this->value);
    }
}
