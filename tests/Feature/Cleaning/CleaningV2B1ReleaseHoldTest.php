<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * This is an explicit release HOLD, not the future B1 acceptance suite.
 * Delete/replace these expectations only after P3 root/lock adoption and the
 * separately frozen B1 authenticated MySQL concurrency proof are accepted.
 */
it('does not publish either proposed supervisor V2 B1 task mutation route', function (): void {
    $allRoutes = collect(Route::getRoutes()->getRoutes());
    $v2TaskRoutes = $allRoutes->filter(static function (RoutingRoute $route): bool {
        return str_contains($route->uri(), 'supervisor/v2/bookings/')
            && preg_match('#(?:^|/)tasks(?:/|$)#', $route->uri()) === 1;
    });

    expect($v2TaskRoutes->values()->all())->toBe([]);

    $base = '/api/v1/supervisor/v2/bookings/101/visits/1';
    foreach ([
        ['POST', $base.'/tasks/materialize'],
        ['PUT', $base.'/tasks/123/participants'],
    ] as [$method, $uri]) {
        expect(fn () => Route::getRoutes()->match(Request::create($uri, $method)))
            ->toThrow(NotFoundHttpException::class);
    }
});
