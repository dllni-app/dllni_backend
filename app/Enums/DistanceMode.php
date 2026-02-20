<?php

declare(strict_types=1);

namespace App\Enums;

enum DistanceMode: string
{
    case CurrentLocation = 'current_location';
    case HomeAddress = 'home_address';
    case SmartAutomatic = 'smart_automatic';
}
