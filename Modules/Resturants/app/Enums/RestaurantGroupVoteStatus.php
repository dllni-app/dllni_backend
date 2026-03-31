<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum RestaurantGroupVoteStatus: string
{
    case Active = 'active';
    case Ended = 'ended';
    case Cancelled = 'cancelled';
}
