<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum OrderRejectionReason: string
{
    case OutOfStock = 'out_of_stock';
    case KitchenBusy = 'kitchen_busy';
    case ClosingHours = 'closing_hours';
    case Other = 'other';
}
