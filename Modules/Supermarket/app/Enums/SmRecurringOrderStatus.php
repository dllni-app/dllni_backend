<?php

declare(strict_types=1);

namespace Modules\Supermarket\Enums;

enum SmRecurringOrderStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
}
