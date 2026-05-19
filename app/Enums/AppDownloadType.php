<?php

declare(strict_types=1);

namespace App\Enums;

enum AppDownloadType: string
{
    case RestaurantOwnerApp = 'restaurant_owner_app';
    case SupermarketOwnerApp = 'supermarket_owner_app';
    case CleaningWorkerApp = 'cleaning_worker_app';
    case UserApp = 'user_app';
}

