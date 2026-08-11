<?php

declare(strict_types=1);

namespace Modules\User\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\API\UserCleaningHomeContentController;
use Modules\User\Http\Controllers\API\UserCouponsIndexController;

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

        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/api.php'));
    }
}
