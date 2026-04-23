<?php

declare(strict_types=1);

namespace Modules\Supermarket\Enums;

enum SmOrderStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Preparing = 'preparing';
    case ReadyForPickup = 'ready_for_pickup';
    case PickedUp = 'picked_up';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
