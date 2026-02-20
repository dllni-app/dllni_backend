<?php

declare(strict_types=1);

namespace App\Enums;

enum PropertyType: string
{
    case Studio = 'studio';
    case Apartment = 'apartment';
    case Villa = 'villa';
    case Office = 'office';
}
