<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Resturants\Http\Controllers\API\CategoryController;
use Modules\Resturants\Http\Controllers\API\DashboardOverviewController;
use Modules\Resturants\Http\Controllers\API\OfferController;
use Modules\Resturants\Http\Controllers\API\OrderController;
use Modules\Resturants\Http\Controllers\API\ProductController;
use Modules\Resturants\Http\Controllers\API\PromoCodeController;
use Modules\Resturants\Http\Controllers\API\RestaurantAnalyticsController;
use Modules\Resturants\Http\Controllers\API\RestaurantAssistantQueryController;
use Modules\Resturants\Http\Controllers\API\RestaurantController;
use Modules\Resturants\Http\Controllers\API\RestaurantDocumentController;
use Modules\Resturants\Http\Controllers\API\RestaurantOrderDisputeController;
use Modules\Resturants\Http\Controllers\API\RestaurantPenaltyController;
use Modules\Resturants\Http\Controllers\API\RestaurantRecurringOrderController;
use Modules\Resturants\Http\Controllers\API\RestaurantReputationLogController;
use Modules\Resturants\Http\Controllers\API\RestaurantRoleController;
use Modules\Resturants\Http\Controllers\API\RestaurantStaffController;
use Modules\Resturants\Http\Controllers\API\ReviewController;
use Modules\Resturants\Http\Controllers\ResturantsController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('restaurant/dashboard/overview', DashboardOverviewController::class);
    Route::get('restaurant/analytics/daily-stats', [RestaurantAnalyticsController::class, 'dailyStats']);
    Route::get('restaurant/analytics/monthly-stats', [RestaurantAnalyticsController::class, 'monthlyStats']);
    Route::apiResource('restaurants', RestaurantController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('products', ProductController::class);
    Route::apiResource('orders', OrderController::class);
    Route::apiResource('offers', OfferController::class);
    Route::apiResource('promo-codes', PromoCodeController::class);
    Route::apiResource('restaurant-order-disputes', RestaurantOrderDisputeController::class);
    Route::apiResource('restaurant-documents', RestaurantDocumentController::class);
    Route::apiResource('restaurant-reputation-logs', RestaurantReputationLogController::class)->only(['index', 'show']);
    Route::apiResource('restaurant-penalties', RestaurantPenaltyController::class)->only(['index', 'show']);
    Route::apiResource('restaurant-staff', RestaurantStaffController::class)->only(['index', 'show']);
    Route::apiResource('restaurant-roles', RestaurantRoleController::class)->only(['index', 'show']);
    Route::apiResource('restaurant-assistant-queries', RestaurantAssistantQueryController::class)->only(['index', 'show']);
    Route::apiResource('restaurant-recurring-orders', RestaurantRecurringOrderController::class)->only(['index', 'show']);
    Route::apiResource('reviews', ReviewController::class)->only(['index', 'show']);
    Route::apiResource('resturants', ResturantsController::class)->names('resturants');
});
