<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum RestaurantGroupOrderParticipantStatus: string
{
    case Joined = 'joined';
    case Submitted = 'submitted';
}
