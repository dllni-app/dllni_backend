<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum InventoryLogType: string
{
    case Sale = 'sale';
    case Restock = 'restock';
    case Adjustment = 'adjustment';
    case Waste = 'waste';
}
