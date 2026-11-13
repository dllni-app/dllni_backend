<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum OfferType: string
{
    case ProductDiscount = 'product_discount';
    case BuyXGetY = 'buy_x_get_y';
}
