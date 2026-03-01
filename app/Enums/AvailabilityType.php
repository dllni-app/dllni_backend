<?php

declare(strict_types=1);

namespace App\Enums;

enum AvailabilityType: string
{
    case Available = 'available';
    case Blocked = 'blocked';
    case Vacation = 'vacation';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'متاح',
            self::Blocked => 'محظور',
            self::Vacation => 'إجازة',
        };
    }
}
