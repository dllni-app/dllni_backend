<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum EventType: string
{
    case FamilyDinner = 'family_dinner';
    case Birthday = 'birthday';
    case LargeGathering = 'large_gathering';
    case Funeral = 'funeral';
    case Other = 'other';

    public function label(): string
    {
        return __('cleaning_admin.enums.event_type.' . $this->value);
    }
}
