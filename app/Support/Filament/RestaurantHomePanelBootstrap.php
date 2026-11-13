<?php

declare(strict_types=1);

namespace App\Support\Filament;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

/**
 * Avoid discovering dashboard resources for the public restaurant home reads.
 *
 * This is intentionally not a generic API bypass: write endpoints can create
 * notifications with Filament URLs. Console/worker boot and cached route
 * boot must also retain the complete panel registry.
 */
final class RestaurantHomePanelBootstrap
{
    public static function shouldSkip(Application $app): bool
    {
        if ($app->runningInConsole()
            || $app->routesAreCached()
            || ! $app->bound('request')) {
            return false;
        }

        $request = $app->make('request');

        return $request instanceof Request && self::isHomeRead($request);
    }

    public static function isHomeRead(Request $request): bool
    {
        if (! in_array($request->getRealMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }

        return in_array($request->path(), [
            'api/v2/user/restaurants/home',
            'api/v2/user/restaurants/home/promotions',
            'api/v2/user/restaurants/home/food-categories',
            'api/v2/user/restaurants/home/cuisines',
            'api/v2/user/restaurants/home/restaurants',
            'api/v2/user/restaurants/home/popular-meals',
            'api/v2/user/restaurants/home/dishes',
        ], true);
    }
}
