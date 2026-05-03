<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // This backend is API-first and has no named "login" web route.
        // Override Laravel's default guest redirect to avoid Route [login] errors
        // for unauthenticated API requests (they should return 401 instead).
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'dashboard.admin' => App\Http\Middleware\EnsureDashboardAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
