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
use Modules\Delivery\Http\Controllers\API\Driver\DriverUiController;
use Modules\Delivery\Http\Controllers\API\User\DeliveryUserOrderController;
use Modules\Delivery\Http\Middleware\EnsureDeliveryDriver;

Route::prefix('v1/delivery/user')->middleware(['auth:sanctum'])->group(function (): void {
    Route::get('orders', [DeliveryUserOrderController::class, 'index']);
    Route::get('orders/{order}', [DeliveryUserOrderController::class, 'show'])->whereNumber('order');
});

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

        Route::get('bootstrap', [DriverUiController::class, 'bootstrap']);
        Route::get('dashboard/summary', [DriverUiController::class, 'dashboardSummary']);
        Route::get('orders/status-counts', [DriverUiController::class, 'statusCounts']);
        Route::get('orders/{order}/offer-state', [DriverUiController::class, 'offerState'])->whereNumber('order');
        Route::get('orders/{order}/timeline', [DriverUiController::class, 'timeline'])->whereNumber('order');
        Route::get('orders/{order}', [DriverUiController::class, 'show'])->whereNumber('order');
        Route::get('orders', [DriverUiController::class, 'indexOrders']);
        Route::post('orders/{order}/arrived-pickup', [DriverUiController::class, 'arrivedPickup'])->whereNumber('order');
        Route::post('orders/{order}/arrived-dropoff', [DriverUiController::class, 'arrivedDropoff'])->whereNumber('order');
        Route::post('orders/{order}/call-events', [DriverUiController::class, 'callEvent'])->whereNumber('order');

        Route::get('wallet/transactions', [DriverUiController::class, 'walletTransactions']);
        Route::get('wallet/limits', [DriverUiController::class, 'walletLimits']);

        Route::post('notifications/read-all', [DriverUiController::class, 'readAllNotifications']);
        Route::get('notifications/unread-count', [DriverUiController::class, 'unreadNotificationCount']);

        Route::get('config/reject-reasons', [DriverUiController::class, 'rejectReasons']);
        Route::get('app/version-check', [DriverUiController::class, 'versionCheck']);
        Route::post('push/register', [DriverUiController::class, 'registerPushToken']);
        Route::post('push/unregister', [DriverUiController::class, 'unregisterPushToken']);
    });
});
