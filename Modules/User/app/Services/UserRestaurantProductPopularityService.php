<?php

declare(strict_types=1);

namespace Modules\User\Services;

final class UserRestaurantProductPopularityService
{
    private const int MOST_ORDERED_MIN_ORDERS_LAST_30_DAYS = 5;

    public static function mostOrderedMinOrders(): int
    {
        return self::MOST_ORDERED_MIN_ORDERS_LAST_30_DAYS;
    }
}
