<?php

declare(strict_types=1);

namespace Modules\User\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRestaurantV2Enabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('restaurant_v2.enabled', false), 404);

        return $next($request);
    }
}
