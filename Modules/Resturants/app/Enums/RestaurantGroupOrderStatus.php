<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum RestaurantGroupOrderStatus: string
{
    case Active = 'active';
    case Placing = 'placing';
    case Placed = 'placed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
