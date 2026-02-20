<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum CleaningBillingMode: string
{
    case FullBookedTime = 'full_booked_time';
    case ActualWorkingTime = 'actual_working_time';
}
