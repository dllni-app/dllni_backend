<?php

declare(strict_types=1);

namespace Modules\Supermarket\Support;

use Illuminate\Http\Request;

final class SupermarketPromotionCapability
{
    public static function enabled(): bool
    {
        return (bool) config('supermarket-promotions.enabled', false);
    }

    public static function supported(?Request $request = null): bool
    {
        return self::enabled() && self::clientSupports($request);
    }

    public static function clientSupports(?Request $request = null): bool
    {
        return ($request ?? request())->header('X-Supermarket-Promotions') === '1';
    }
}
