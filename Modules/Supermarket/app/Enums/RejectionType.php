<?php

declare(strict_types=1);

namespace Modules\Supermarket\Enums;

enum RejectionType: string
{
    case OutOfStock = 'out_of_stock';
    case FakeOrder = 'fake_order';
    case Other = 'other';
}
