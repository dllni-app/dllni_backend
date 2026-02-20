<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum RecurringOrderStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
}
