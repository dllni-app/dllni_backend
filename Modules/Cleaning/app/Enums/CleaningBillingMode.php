<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum CleaningBillingMode: string
{
    case FullBookedTime = 'full_booked_time';
    case ActualWorkingTime = 'actual_working_time';

    public function label(): string
    {
        return match ($this) {
            self::FullBookedTime => 'فوترة الوقت المحجوز بالكامل',
            self::ActualWorkingTime => 'فوترة وقت العمل الفعلي',
        };
    }
}
