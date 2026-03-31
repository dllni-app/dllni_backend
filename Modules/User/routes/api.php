<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\API\DiscoverRestaurantsController;
use Modules\User\Http\Controllers\API\LoginController;
use Modules\User\Http\Controllers\API\LoginVerifyController;
use Modules\User\Http\Controllers\API\MeController;
use Modules\User\Http\Controllers\API\RegisterController;
use Modules\User\Http\Controllers\API\ResetPasswordConfirmController;
use Modules\User\Http\Controllers\API\ResetPasswordController;
use Modules\User\Http\Controllers\API\SmHomeFeaturedOffersController;
use Modules\User\Http\Controllers\API\SmHomeNearbyStoresController;
use Modules\User\Http\Controllers\API\SmStoresIndexController;
use Modules\User\Http\Controllers\API\UserRestaurantDetailsController;
use Modules\User\Http\Controllers\API\VerifyAccountController;

Route::prefix('v1/user')->group(function (): void {
    Route::post('register', RegisterController::class);
    Route::post('verify-account', VerifyAccountController::class);

    Route::post('login', LoginController::class);
    Route::post('login/verify', LoginVerifyController::class);

    Route::post('reset-password', ResetPasswordController::class);
    Route::post('reset-password/confirm', ResetPasswordConfirmController::class);

    Route::prefix('supermarket/home')->group(function (): void {
        Route::get('featured-offers', SmHomeFeaturedOffersController::class);
        Route::get('nearby-stores', SmHomeNearbyStoresController::class);
    });

    Route::get('supermarket/stores', SmStoresIndexController::class);

    Route::get('restaurants/discover', DiscoverRestaurantsController::class);
    Route::get('restaurants/{restaurant}', UserRestaurantDetailsController::class);

    Route::middleware(['auth:sanctum'])->get('me', MeController::class);
});
