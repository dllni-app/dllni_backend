<?php

declare(strict_types=1);

namespace Modules\Delivery\Enums;

enum DeliveryDriverAvailabilityStatus: string
{
    case Available = 'available';
    case Busy = 'busy';
    case Offline = 'offline';
}
