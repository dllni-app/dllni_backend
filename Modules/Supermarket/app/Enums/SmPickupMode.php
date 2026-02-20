<?php

declare(strict_types=1);

namespace Modules\Supermarket\Enums;

enum SmPickupMode: string
{
    case ImmediatePickup = 'immediate_pickup';
    case ScheduledPickup = 'scheduled_pickup';
}
