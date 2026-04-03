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
        return __('cleaning_admin.enums.availability_type.'.$this->value);
    }
}
