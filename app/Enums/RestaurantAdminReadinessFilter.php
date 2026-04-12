<?php

declare(strict_types=1);

namespace App\Enums;

enum RestaurantAdminReadinessFilter: string
{
    case MissingOperatingHours = 'missing_operating_hours';
    case MissingCuisineTypes = 'missing_cuisine_types';
    case MissingAvailableProducts = 'missing_available_products';
    case MissingActiveOffers = 'missing_active_offers';
    case MissingActiveCoupons = 'missing_active_coupons';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = __('restaurant_admin.filters.readiness.'.$case->value);
        }

        return $out;
    }
}
