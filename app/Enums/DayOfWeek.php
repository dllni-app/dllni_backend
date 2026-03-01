<?php

declare(strict_types=1);

namespace App\Enums;

enum DayOfWeek: string
{
    case Sunday = 'sunday';
    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
