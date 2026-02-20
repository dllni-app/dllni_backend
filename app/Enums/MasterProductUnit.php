<?php

declare(strict_types=1);

namespace App\Enums;

enum MasterProductUnit: string
{
    case Piece = 'piece';
    case Gram = 'gram';
    case Kilogram = 'kilogram';
    case Milliliter = 'milliliter';
    case Liter = 'liter';
    case Pack = 'pack';
}
