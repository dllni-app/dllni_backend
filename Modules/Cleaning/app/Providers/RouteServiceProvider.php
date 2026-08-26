<?php

declare(strict_types=1);

namespace Modules\Cleaning\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingSessionController;
use Modules\Cleaning\Http\Middleware\AppendCleaningBookingScheduleResponse;
use Modules\Cleaning\Http\Middleware\RequireSessionScopedCleaningLifecycle;

final class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Cleaning';

    public function boot(): void
    {
        parent::boot();
    }

    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();
    }

    public function mapWebRoutes(): void
    {
        Route::middleware('web')->group(module_path($this->name, '/routes/web.php'));
    }

    public function mapApiRoutes(): void
    {
        Route::middleware([
            'api',
            AppendCleaningBookingScheduleResponse::class,
            RequireSessionScopedCleaningLifecycle::class,
        ])->prefix('api')->name('api.')->group(module_path($this->name, '/routes/api.php'));

        Route::middleware([
            'api',
            'auth:sanctum',
            AppendCleaningBookingScheduleResponse::class,
        ])->prefix('api/v1')->name('api.')->group(function (): void {
            Route::prefix('cleaning-bookings/{booking}/sessions/{session}')->scopeBindings()->group(function (): void {
                Route::post('start-travel', [CleaningBookingSessionController::class, 'startTravel'])->name('cleaning-bookings.sessions.start-travel');
                Route::post('location', [CleaningBookingSessionController::class, 'location'])->name('cleaning-bookings.sessions.location');
                Route::post('arrive', [CleaningBookingSessionController::class, 'arrive'])->name('cleaning-bookings.sessions.arrive');
                Route::get('security-code', [CleaningBookingSessionController::class, 'securityCode'])->name('cleaning-bookings.sessions.security-code');
                Route::post('start-work', [CleaningBookingSessionController::class, 'startWork'])->name('cleaning-bookings.sessions.start-work');
                Route::post('complete', [CleaningBookingSessionController::class, 'complete'])->name('cleaning-bookings.sessions.complete');
                Route::post('sos', [CleaningBookingSessionController::class, 'sos'])->name('cleaning-bookings.sessions.sos');
            });
        });
    }
}
