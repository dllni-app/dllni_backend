<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum RestaurantPickupMode: string
{
    case ImmediatePickup = 'immediate_pickup';
    case ScheduledPickup = 'scheduled_pickup';
}
