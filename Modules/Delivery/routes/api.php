<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Delivery\Http\Controllers\API\Driver\DriverAuthController;
use Modules\Delivery\Http\Controllers\API\Driver\DriverAvailabilityController;
use Modules\Delivery\Http\Controllers\API\Driver\DriverDisputeController;
use Modules\Delivery\Http\Controllers\API\Driver\DriverFinancialController;
use Modules\Delivery\Http\Controllers\API\Driver\DriverLocationController;
use Modules\Delivery\Http\Controllers\API\Driver\DriverNotificationController;
use Modules\Delivery\Http\Controllers\API\Driver\DriverOfferController;
use Modules\Delivery\Http\Controllers\API\Driver\DriverOrderController;
use Modules\Delivery\Http\Middleware\EnsureDeliveryDriver;

Route::prefix('v1/delivery/driver')->group(function (): void {
    Route::post('auth/login', [DriverAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', EnsureDeliveryDriver::class])->group(function (): void {
        Route::post('auth/logout', [DriverAuthController::class, 'logout']);
        Route::get('me', [DriverAuthController::class, 'me']);

        Route::patch('availability', DriverAvailabilityController::class);
        Route::post('location', DriverLocationController::class);

        Route::get('offers/current', [DriverOfferController::class, 'current']);
        Route::post('offers/{attempt}/accept', [DriverOfferController::class, 'accept'])->whereNumber('attempt');
        Route::post('offers/{attempt}/reject', [DriverOfferController::class, 'reject'])->whereNumber('attempt');

        Route::get('orders/current', [DriverOrderController::class, 'current']);
        Route::post('orders/{order}/start', [DriverOrderController::class, 'start'])->whereNumber('order');
        Route::post('orders/{order}/pickup', [DriverOrderController::class, 'pickup'])->whereNumber('order');
        Route::post('orders/{order}/deliver', [DriverOrderController::class, 'deliver'])->whereNumber('order');

        Route::get('financial/summary', DriverFinancialController::class);

        Route::get('notifications', [DriverNotificationController::class, 'index']);
        Route::patch('notifications/{id}/read', [DriverNotificationController::class, 'markAsRead']);

        Route::get('disputes', DriverDisputeController::class);
    });
});
