<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureSellerPermission;
use Illuminate\Support\Facades\Route;
use Modules\Supermarket\Http\Controllers\API\SmCategoryController;
use Modules\Supermarket\Http\Controllers\API\SmCouponController;
use Modules\Supermarket\Http\Controllers\API\SmOfferController;
use Modules\Supermarket\Http\Controllers\API\SmOrderController;
use Modules\Supermarket\Http\Controllers\API\SmProductController;
use Modules\Supermarket\Http\Controllers\API\SmStoreHoursController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\SmOrderPreparationEstimateController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\SmOrderStatusController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerActivityLogController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerDashboardController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerEmployeeIndexController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerEmployeePasswordUpdateController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerEmployeeStatusController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerEmployeeStoreController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerEmployeeUpdateController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerInventoryController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerInventoryCountsController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerMasterProductCreateController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerMasterProductSearchController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerOfferWeeklySummaryController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerOrderCountsController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerPermissionsController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerStoreController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerTopSellingProductsController;
use Modules\Supermarket\Http\Middleware\InjectStoreIdFromOwnerContext;

$productsPermission = EnsureSellerPermission::class.':so.products';
$offersPermission = EnsureSellerPermission::class.':so.offers_coupons';
$ordersPermission = EnsureSellerPermission::class.':so.orders';
$staffPermission = EnsureSellerPermission::class.':so.staff_register';
$storePermission = EnsureSellerPermission::class.':so.store_hours';
$warehousePermission = EnsureSellerPermission::class.':so.warehouse';

Route::prefix('v1')->middleware(['auth:sanctum', InjectStoreIdFromOwnerContext::class])->group(function () use (
    $productsPermission,
    $offersPermission,
    $ordersPermission,
    $staffPermission,
    $storePermission,
    $warehousePermission,
): void {
    Route::apiResource('sm-categories', SmCategoryController::class)
        ->middleware($productsPermission)
        ->names('sm-categories');
    Route::get('sm-products/available-count', [SmProductController::class, 'availableCount'])
        ->middleware($productsPermission)
        ->name('sm-products.available-count');
    Route::post('sm-products/import', [SmProductController::class, 'import'])
        ->middleware($productsPermission)
        ->name('sm-products.import');
    Route::get('sm-products/search', [SmProductController::class, 'index'])
        ->middleware($productsPermission)
        ->name('sm-products.search');
    Route::apiResource('sm-products', SmProductController::class)
        ->middleware($productsPermission)
        ->names('sm-products');
    Route::apiResource('sm-offers', SmOfferController::class)
        ->middleware($offersPermission)
        ->names('sm-offers');
    Route::get('sm-coupons/weekly-analysis', [SmCouponController::class, 'weeklyAnalysis'])
        ->middleware($offersPermission)
        ->name('sm-coupons.weekly-analysis');
    Route::apiResource('sm-coupons', SmCouponController::class)
        ->middleware($offersPermission)
        ->names('sm-coupons');
    Route::get('sm-orders/hourly-count', [SmOrderController::class, 'hourlyCount'])
        ->middleware($ordersPermission)
        ->name('sm-orders.hourly-count');
    Route::apiResource('sm-orders', SmOrderController::class)
        ->middleware($ordersPermission)
        ->names('sm-orders');

    Route::middleware('auth:sanctum')->prefix('store-owner')->name('store-owner.')->group(function () use (
        $productsPermission,
        $offersPermission,
        $ordersPermission,
        $staffPermission,
        $storePermission,
        $warehousePermission,
    ): void {
        Route::get('dashboard', StoreOwnerDashboardController::class)->name('dashboard');
        Route::get('dashboard/top-selling-products', StoreOwnerTopSellingProductsController::class)
            ->middleware($productsPermission)
            ->name('dashboard.top-selling-products');
        Route::get('offers/weekly-summary', StoreOwnerOfferWeeklySummaryController::class)
            ->middleware($offersPermission)
            ->name('offers.weekly-summary');
        Route::get('permissions', StoreOwnerPermissionsController::class)
            ->middleware($staffPermission)
            ->name('permissions');
        Route::get('activity-logs', StoreOwnerActivityLogController::class)
            ->middleware($staffPermission)
            ->name('activity-logs');

        Route::get('employees', StoreOwnerEmployeeIndexController::class)
            ->middleware($staffPermission)
            ->name('employees.index');
        Route::post('employees', StoreOwnerEmployeeStoreController::class)
            ->middleware($staffPermission)
            ->name('employees.store');
        Route::patch('employees/{staff}', StoreOwnerEmployeeUpdateController::class)
            ->middleware($staffPermission)
            ->name('employees.update');
        Route::patch('employees/{staff}/password', StoreOwnerEmployeePasswordUpdateController::class)
            ->middleware($staffPermission)
            ->name('employees.password');
        Route::patch('employees/{staff}/status', StoreOwnerEmployeeStatusController::class)
            ->middleware($staffPermission)
            ->name('employees.status');

        Route::get('master-products/search', StoreOwnerMasterProductSearchController::class)
            ->middleware($productsPermission)
            ->name('master-products.search');
        Route::post('products/from-master', StoreOwnerMasterProductCreateController::class)
            ->middleware($productsPermission)
            ->name('products.from-master');

        Route::get('orders/counts', StoreOwnerOrderCountsController::class)
            ->middleware($ordersPermission)
            ->name('orders.counts');
        Route::post('orders/{order}/accept', [SmOrderStatusController::class, 'accept'])
            ->middleware($ordersPermission)
            ->name('orders.accept');
        Route::post('orders/{order}/preparing', [SmOrderStatusController::class, 'preparing'])
            ->middleware($ordersPermission)
            ->name('orders.preparing');
        Route::patch('orders/{order}/preparation-estimate', SmOrderPreparationEstimateController::class)
            ->middleware($ordersPermission)
            ->name('orders.preparation-estimate');
        Route::post('orders/{order}/ready-for-pickup', [SmOrderStatusController::class, 'readyForPickup'])
            ->middleware($ordersPermission)
            ->name('orders.ready-for-pickup');
        Route::post('orders/{order}/cancel', [SmOrderStatusController::class, 'cancel'])
            ->middleware($ordersPermission)
            ->name('orders.cancel');
        Route::post('orders/{order}/reject', [SmOrderStatusController::class, 'reject'])
            ->middleware($ordersPermission)
            ->name('orders.reject');
        Route::post('orders/{order}/courier-handover', [SmOrderStatusController::class, 'courierHandover'])
            ->middleware($ordersPermission)
            ->name('orders.courier-handover');
        Route::post('orders/{order}/return', [StoreOwnerInventoryController::class, 'processReturn'])
            ->middleware($ordersPermission)
            ->name('orders.return');

        Route::get('inventory/counts', StoreOwnerInventoryCountsController::class)
            ->middleware($warehousePermission)
            ->name('inventory.counts');
        Route::get('inventory/summary', [StoreOwnerInventoryController::class, 'inventorySummary'])
            ->middleware($warehousePermission)
            ->name('inventory.summary');
        Route::get('products/low-stock', [StoreOwnerInventoryController::class, 'lowStock'])
            ->middleware($warehousePermission)
            ->name('products.low-stock');
        Route::put('products/{product}/stock', [StoreOwnerInventoryController::class, 'updateStock'])
            ->middleware($warehousePermission)
            ->name('products.update-stock');
        Route::put('products/{product}/expiration', [StoreOwnerInventoryController::class, 'updateExpiration'])
            ->middleware($warehousePermission)
            ->name('products.update-expiration');
        Route::post('inventory/audit', [StoreOwnerInventoryController::class, 'audit'])
            ->middleware($warehousePermission)
            ->name('inventory.audit');
        Route::get('reports/lost-opportunities', [StoreOwnerInventoryController::class, 'lostOpportunities'])
            ->middleware($warehousePermission)
            ->name('reports.lost-opportunities');

        Route::apiResource('products', SmProductController::class)
            ->middleware($productsPermission)
            ->names('products');

        Route::get('store', [StoreOwnerStoreController::class, 'show'])
            ->middleware($storePermission)
            ->name('stores.show');
        Route::put('store', [StoreOwnerStoreController::class, 'update'])
            ->middleware($storePermission)
            ->name('stores.update');
        Route::get('store/operating-hours', [SmStoreHoursController::class, 'show'])
            ->middleware($storePermission);
        Route::put('store/operating-hours', [SmStoreHoursController::class, 'update'])
            ->middleware($storePermission);
    });
});
