<?php

declare(strict_types=1);

namespace Modules\Resturants\Support;

use Illuminate\Http\Request;

final class RestaurantPromotionCapability
{
    public static function enabled(): bool
    {
        return (bool) config('restaurant-promotions.enabled', false);
    }

    public static function supported(?Request $request = null): bool
    {
        return self::enabled() && self::clientSupports($request);
    }

    public static function clientSupports(?Request $request = null): bool
    {
        return ($request ?? request())->header('X-Restaurant-Promotions') === '1';
    }
}