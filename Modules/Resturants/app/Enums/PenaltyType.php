<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum PenaltyType: string
{
    case Warning = 'warning';
    case Fine = 'fine';
    case Suspension = 'suspension';
}
