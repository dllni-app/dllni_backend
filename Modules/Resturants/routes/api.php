<?php

declare(strict_types=1);

use App\Http\Controllers\API\ProductAiController as AppProductAiController;
use App\Http\Middleware\EnsureSellerPermission;
use Illuminate\Support\Facades\Route;
use Modules\Resturants\Http\Controllers\API\CategoryController;
use Modules\Resturants\Http\Controllers\API\DashboardOverviewController;
use Modules\Resturants\Http\Controllers\API\InventoryAlertsController;
use Modules\Resturants\Http\Controllers\API\InventoryItemController;
use Modules\Resturants\Http\Controllers\API\InventorySummaryController;
use Modules\Resturants\Http\Controllers\API\OfferController;
use Modules\Resturants\Http\Controllers\API\OrderAcceptController;
use Modules\Resturants\Http\Controllers\API\OrderController;
use Modules\Resturants\Http\Controllers\API\OrderInvoiceController;
use Modules\Resturants\Http\Controllers\API\OrderRejectController;
use Modules\Resturants\Http\Controllers\API\ProductController;
use Modules\Resturants\Http\Controllers\API\PromoCodeController;
use Modules\Resturants\Http\Controllers\API\RestaurantAnalyticsController;
use Modules\Resturants\Http\Controllers\API\RestaurantAssistantQueryController;
use Modules\Resturants\Http\Controllers\API\RestaurantController;
use Modules\Resturants\Http\Controllers\API\RestaurantDocumentController;
use Modules\Resturants\Http\Controllers\API\RestaurantOperatingHoursController;
use Modules\Resturants\Http\Controllers\API\RestaurantOrderDisputeController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOrderPreparationEstimateController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerActivityLogController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerCouponsIndexController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerCouponSummaryController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerDashboardPerformanceController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerEmployeeDestroyController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerEmployeeIndexController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerEmployeeStoreController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerEmployeeUpdateController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerNotificationMarkReadAllController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerNotificationMarkReadController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerNotificationsController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerOffersController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerOffersIndexController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerOfferSummaryController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerOrderIndexController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerOrderItemDestroyController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerOrderItemStoreController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerOrderItemUpdateController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerOrderShowController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerOrderStatusController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerPermissionsController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerProductAvailabilityController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerPromoCodesController;
use Modules\Resturants\Http\Controllers\API\RestaurantOwner\RestaurantOwnerTopSellingProductsController;
use Modules\Resturants\Http\Controllers\API\RestaurantPenaltyController;
use Modules\Resturants\Http\Controllers\API\RestaurantRecurringOrderController;
use Modules\Resturants\Http\Controllers\API\RestaurantReputationLogController;
use Modules\Resturants\Http\Controllers\API\RestaurantRoleController;
use Modules\Resturants\Http\Controllers\API\RestaurantSearchController;
use Modules\Resturants\Http\Controllers\API\RestaurantStaffController;
use Modules\Resturants\Http\Controllers\API\ReviewController;
use Modules\Resturants\Http\Controllers\ResturantsController;

$menuPermission = EnsureSellerPermission::class.':ro.menu';
$offersPermission = EnsureSellerPermission::class.':ro.offers_coupons';
$ordersPermission = EnsureSellerPermission::class.':ro.orders';
$staffPermission = EnsureSellerPermission::class.':ro.staff_register';
$storePermission = EnsureSellerPermission::class.':ro.store_hours';
$warehousePermission = EnsureSellerPermission::class.':ro.warehouse';
$productAiPermission = EnsureSellerPermission::class.':ro.menu,so.products';

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () use (
    $menuPermission,
    $offersPermission,
    $ordersPermission,
    $staffPermission,
    $storePermission,
    $warehousePermission,
    $productAiPermission,
): void {
    Route::prefix('products/ai')->middleware($productAiPermission)->group(function (): void {
        Route::post('extract-from-image', [AppProductAiController::class, 'extractFromImage'])->name('sm-products.ai.extract-from-image');
        Route::post('extract-from-menu', [AppProductAiController::class, 'extractFromMenu'])->name('sm-products.ai.extract-from-menu');
        Route::post('generate-image', [AppProductAiController::class, 'generateImage'])->name('sm-products.ai.generate-image');
    });

    Route::apiResource('restaurants', RestaurantController::class);

    Route::apiResource('inventory-items', InventoryItemController::class)
        ->middleware($warehousePermission);
    Route::apiResource('categories', CategoryController::class)
        ->middleware($menuPermission);
    Route::post('orders/{order}/accept', OrderAcceptController::class)
        ->middleware($ordersPermission)
        ->name('orders.accept');
    Route::post('orders/{order}/reject', OrderRejectController::class)
        ->middleware($ordersPermission)
        ->name('orders.reject');
    Route::get('orders/{order}/invoice', OrderInvoiceController::class)
        ->middleware($ordersPermission)
        ->name('orders.invoice');
    Route::apiResource('orders', OrderController::class)
        ->middleware($ordersPermission);
    Route::apiResource('offers', OfferController::class)
        ->middleware($offersPermission);
    Route::apiResource('promo-codes', PromoCodeController::class)
        ->middleware($offersPermission);
    Route::apiResource('restaurant-order-disputes', RestaurantOrderDisputeController::class);
    Route::apiResource('restaurant-documents', RestaurantDocumentController::class);
    Route::apiResource('restaurant-reputation-logs', RestaurantReputationLogController::class)->only(['index', 'show']);
    Route::apiResource('restaurant-penalties', RestaurantPenaltyController::class)->only(['index', 'show']);
    Route::apiResource('restaurant-staff', RestaurantStaffController::class)
        ->middleware($staffPermission);
    Route::apiResource('restaurant-assistant-queries', RestaurantAssistantQueryController::class)->only(['index', 'show']);
    Route::apiResource('restaurant-recurring-orders', RestaurantRecurringOrderController::class)->only(['index', 'show']);
    Route::apiResource('reviews', ReviewController::class)->only(['index', 'show']);
    Route::apiResource('resturants', ResturantsController::class)->names('resturants');

    Route::apiResource('products', ProductController::class)
        ->middleware($menuPermission);

    Route::prefix('restaurant')->group(function () use ($warehousePermission): void {
        Route::get('dashboard/overview', DashboardOverviewController::class);
        Route::get('analytics/daily-stats', [RestaurantAnalyticsController::class, 'dailyStats']);
        Route::get('analytics/monthly-stats', [RestaurantAnalyticsController::class, 'monthlyStats']);
        Route::get('search/products', RestaurantSearchController::class);
        Route::get('inventory-summary', InventorySummaryController::class)
            ->middleware($warehousePermission);
        Route::get('inventory-alerts', InventoryAlertsController::class)
            ->middleware($warehousePermission);
    });

    Route::prefix('restaurant-owner')->group(function () use (
        $menuPermission,
        $offersPermission,
        $ordersPermission,
        $staffPermission,
        $storePermission,
        $warehousePermission,
    ): void {
        Route::get('dashboard/overview', DashboardOverviewController::class);
        Route::get('analytics/daily-stats', [RestaurantAnalyticsController::class, 'dailyStats']);
        Route::get('analytics/monthly-stats', [RestaurantAnalyticsController::class, 'monthlyStats']);
        Route::get('search/products', RestaurantSearchController::class)
            ->middleware($menuPermission);
        Route::get('inventory-summary', InventorySummaryController::class)
            ->middleware($warehousePermission);
        Route::get('inventory-alerts', InventoryAlertsController::class)
            ->middleware($warehousePermission);
        Route::apiResource('products', ProductController::class)
            ->middleware($menuPermission)
            ->names('restaurant-owner.products');

        Route::get('restaurant', [RestaurantController::class, 'show'])
            ->middleware($storePermission);
        Route::put('restaurant', [RestaurantController::class, 'update'])
            ->middleware($storePermission);
        Route::get('restaurant/operating-hours', [RestaurantOperatingHoursController::class, 'show'])
            ->middleware($storePermission);
        Route::put('restaurant/operating-hours', [RestaurantOperatingHoursController::class, 'update'])
            ->middleware($storePermission);

        Route::get('dashboard/performance', RestaurantOwnerDashboardPerformanceController::class);
        Route::get('dashboard/top-selling-products', RestaurantOwnerTopSellingProductsController::class)
            ->middleware($menuPermission);
        Route::apiResource('restaurant-roles', RestaurantRoleController::class)
            ->middleware($staffPermission);

        Route::get('orders', RestaurantOwnerOrderIndexController::class)
            ->middleware($ordersPermission);
        Route::get('orders/{order}', RestaurantOwnerOrderShowController::class)
            ->middleware($ordersPermission);
        Route::patch('orders/{order}/status', RestaurantOwnerOrderStatusController::class)
            ->middleware($ordersPermission);
        Route::patch('orders/{order}/preparation-estimate', RestaurantOrderPreparationEstimateController::class)
            ->middleware($ordersPermission);
        Route::post('orders/{order}/items', RestaurantOwnerOrderItemStoreController::class)
            ->middleware($ordersPermission);
        Route::patch('orders/{order}/items/{item}', RestaurantOwnerOrderItemUpdateController::class)
            ->middleware($ordersPermission);
        Route::delete('orders/{order}/items/{item}', RestaurantOwnerOrderItemDestroyController::class)
            ->middleware($ordersPermission);

        Route::patch('products/{product}/availability', RestaurantOwnerProductAvailabilityController::class)
            ->middleware($menuPermission);

        Route::get('offers', RestaurantOwnerOffersIndexController::class)
            ->middleware($offersPermission);
        Route::get('offers/summary', RestaurantOwnerOfferSummaryController::class)
            ->middleware($offersPermission);
        Route::get('coupons', RestaurantOwnerCouponsIndexController::class)
            ->middleware($offersPermission);
        Route::apiResource('offers', RestaurantOwnerOffersController::class)
            ->except('index')
            ->middleware($offersPermission)
            ->names('restaurant-owner.offers');
        Route::apiResource('promo-codes', RestaurantOwnerPromoCodesController::class)
            ->middleware($offersPermission)
            ->names('restaurant-owner.promo-codes');
        Route::get('coupons/summary', RestaurantOwnerCouponSummaryController::class)
            ->middleware($offersPermission);

        Route::get('employees', RestaurantOwnerEmployeeIndexController::class)
            ->middleware($staffPermission);
        Route::post('employees', RestaurantOwnerEmployeeStoreController::class)
            ->middleware($staffPermission);
        Route::patch('employees/{employee}', RestaurantOwnerEmployeeUpdateController::class)
            ->middleware($staffPermission);
        Route::delete('employees/{employee}', RestaurantOwnerEmployeeDestroyController::class)
            ->middleware($staffPermission);

        Route::get('permissions', RestaurantOwnerPermissionsController::class)
            ->middleware($staffPermission);

        Route::get('notifications', RestaurantOwnerNotificationsController::class);
        Route::patch('notifications/read-all', RestaurantOwnerNotificationMarkReadAllController::class);
        Route::patch('notifications/{notification}/read', RestaurantOwnerNotificationMarkReadController::class);

        Route::get('activity-logs', RestaurantOwnerActivityLogController::class)
            ->middleware($staffPermission);
        Route::get('employees/activity', RestaurantOwnerActivityLogController::class)
            ->middleware($staffPermission);
    });

    Route::prefix('resturant-owner')->middleware($offersPermission)->group(function (): void {
        Route::get('offers', RestaurantOwnerOffersIndexController::class);
        Route::get('offers/summary', RestaurantOwnerOfferSummaryController::class);
        Route::apiResource('offers', RestaurantOwnerOffersController::class)
            ->except('index')
            ->names('resturant-owner.offers');
    });
});
