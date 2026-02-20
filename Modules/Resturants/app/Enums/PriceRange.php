<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum PriceRange: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Premium = 'premium';
}
