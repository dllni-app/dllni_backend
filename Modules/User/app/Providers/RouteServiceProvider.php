<?php

declare(strict_types=1);

namespace Modules\User\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\Cleaning\Http\Middleware\AppendCleaningBookingScheduleResponse;
use Modules\Cleaning\Http\Middleware\RequireSessionScopedCleaningLifecycle;
use Modules\User\Http\Controllers\API\UserCleaningHomeContentController;
use Modules\User\Http\Controllers\API\UserCleaningOrderSessionController;
use Modules\User\Http\Controllers\API\UserCouponsIndexController;
use Modules\User\Http\Middleware\NormalizeCleaningMultiDayScheduleRequest;

final class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'User';

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
        Route::middleware(['api', 'auth:sanctum'])
            ->prefix('api/v1/user')
            ->name('api.')
            ->group(function (): void {
                Route::get('cleaning/orders/female-worker-safety-policy', \Modules\User\Http\Controllers\API\UserCleaningFemaleWorkerSafetyPolicyController::class);
                Route::get('coupons', UserCouponsIndexController::class);
            });

        Route::middleware('api')
            ->prefix('api/v1/user')
            ->name('api.')
            ->get('cleaning/home/content', UserCleaningHomeContentController::class);

        Route::middleware([
            'api',
            NormalizeCleaningMultiDayScheduleRequest::class,
            AppendCleaningBookingScheduleResponse::class,
            RequireSessionScopedCleaningLifecycle::class,
        ])->prefix('api')->name('api.')->group(module_path($this->name, '/routes/api.php'));

        Route::middleware([
            'api',
            'auth:sanctum',
            AppendCleaningBookingScheduleResponse::class,
        ])->prefix('api/v1/user')->name('api.')->group(function (): void {
            Route::prefix('cleaning/orders/{booking}/sessions/{session}')->scopeBindings()->group(function (): void {
                Route::post('start-verification/confirm', [UserCleaningOrderSessionController::class, 'confirmStart'])->name('user.cleaning.sessions.start-verification.confirm');
                Route::post('completion/confirm', [UserCleaningOrderSessionController::class, 'confirmCompletion'])->name('user.cleaning.sessions.completion.confirm');
                Route::post('completion/reject', [UserCleaningOrderSessionController::class, 'rejectCompletion'])->name('user.cleaning.sessions.completion.reject');
                Route::post('completion/extend-time', [UserCleaningOrderSessionController::class, 'extendTime'])->name('user.cleaning.sessions.completion.extend-time');
                Route::post('cancel', [UserCleaningOrderSessionController::class, 'cancel'])->name('user.cleaning.sessions.cancel');
            });
        });
    }
}
