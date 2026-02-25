<?php

declare(strict_types=1);

namespace Modules\Supermarket\Enums;

enum SmStockOperation: string
{
    case SET = 'SET';
    case INCREMENT = 'INCREMENT';
    case DECREMENT = 'DECREMENT';
}
