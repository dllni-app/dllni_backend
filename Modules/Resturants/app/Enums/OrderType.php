<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum OrderType: string
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';
    case DineIn = 'dine_in';
}
