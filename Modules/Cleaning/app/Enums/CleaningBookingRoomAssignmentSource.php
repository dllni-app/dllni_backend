<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum CleaningBookingRoomAssignmentSource: string
{
    case Customer = 'customer';
    case Worker = 'worker';
    case Auto = 'auto';
    case Admin = 'admin';
}
