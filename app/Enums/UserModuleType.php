<?php

declare(strict_types=1);

namespace App\Enums;

enum UserModuleType: string
{
    case CleaningWorker = 'cleaning_worker';
    case RestaurantSeller = 'restaurant_seller';
    case SupermarketSeller = 'supermarket_seller';
    case DeliveryDriver = 'delivery_driver';
}
