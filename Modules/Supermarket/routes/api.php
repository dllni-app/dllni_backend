<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Supermarket\Http\Controllers\API\SmCategoryController;
use Modules\Supermarket\Http\Controllers\API\SmCouponController;
use Modules\Supermarket\Http\Controllers\API\SmOfferController;
use Modules\Supermarket\Http\Controllers\API\SmOrderController;
use Modules\Supermarket\Http\Controllers\API\SmProductController;
use Modules\Supermarket\Http\Controllers\API\SmStoreHoursController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\SmOrderStatusController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerActivityLogController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerDashboardController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerEmployeeIndexController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerEmployeePasswordUpdateController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerEmployeeStatusController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerEmployeeStoreController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerEmployeeUpdateController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerInventoryController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerMasterProductCreateController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerMasterProductSearchController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerOfferWeeklySummaryController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerPermissionsController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerStoreController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerTopSellingProductsController;
use Modules\Supermarket\Http\Middleware\InjectStoreIdFromOwnerContext;

Route::prefix('v1')->middleware(['auth:sanctum', InjectStoreIdFromOwnerContext::class])->group(function () {
    Route::apiResource('sm-categories', SmCategoryController::class)->names('sm-categories');
    Route::get('sm-products/available-count', [SmProductController::class, 'availableCount'])->name('sm-products.available-count');
    Route::post('sm-products/import', [SmProductController::class, 'import'])->name('sm-products.import');
    Route::get('sm-products/search', [SmProductController::class, 'index'])->name('sm-products.search');
    Route::apiResource('sm-products', SmProductController::class)->names('sm-products');
    Route::apiResource('sm-offers', SmOfferController::class)->names('sm-offers');
    Route::get('sm-coupons/weekly-analysis', [SmCouponController::class, 'weeklyAnalysis'])->name('sm-coupons.weekly-analysis');
    Route::apiResource('sm-coupons', SmCouponController::class)->names('sm-coupons');
    Route::get('sm-orders/hourly-count', [SmOrderController::class, 'hourlyCount'])->name('sm-orders.hourly-count');
    Route::apiResource('sm-orders', SmOrderController::class)->names('sm-orders');

    // Store Owner Routes
    Route::middleware('auth:sanctum')->prefix('store-owner')->name('store-owner.')->group(function () {
        Route::get('dashboard', StoreOwnerDashboardController::class)->name('dashboard');
        Route::get('dashboard/top-selling-products', StoreOwnerTopSellingProductsController::class)->name('dashboard.top-selling-products');
        Route::get('offers/weekly-summary', StoreOwnerOfferWeeklySummaryController::class)->name('offers.weekly-summary');
        Route::get('permissions', StoreOwnerPermissionsController::class)->name('permissions');
        Route::get('activity-logs', StoreOwnerActivityLogController::class)->name('activity-logs');

        Route::get('employees', StoreOwnerEmployeeIndexController::class)->name('employees.index');
        Route::post('employees', StoreOwnerEmployeeStoreController::class)->name('employees.store');
        Route::patch('employees/{staff}', StoreOwnerEmployeeUpdateController::class)->name('employees.update');
        Route::patch('employees/{staff}/password', StoreOwnerEmployeePasswordUpdateController::class)->name('employees.password');
        Route::patch('employees/{staff}/status', StoreOwnerEmployeeStatusController::class)->name('employees.status');

        Route::get('master-products/search', StoreOwnerMasterProductSearchController::class)->name('master-products.search');
        Route::post('products/from-master', StoreOwnerMasterProductCreateController::class)->name('products.from-master');

        // Order Management
        Route::post('orders/{order}/accept', [SmOrderStatusController::class, 'accept'])->name('orders.accept');
        Route::post('orders/{order}/reject', [SmOrderStatusController::class, 'reject'])->name('orders.reject');
        Route::post('orders/{order}/courier-handover', [SmOrderStatusController::class, 'courierHandover'])->name('orders.courier-handover');
        Route::post('orders/{order}/return', [StoreOwnerInventoryController::class, 'processReturn'])->name('orders.return');

        // Inventory Management - Specific routes before wildcards
        Route::get('inventory/summary', [StoreOwnerInventoryController::class, 'inventorySummary'])->name('inventory.summary');
        Route::get('products/low-stock', [StoreOwnerInventoryController::class, 'lowStock'])->name('products.low-stock');
        Route::put('products/{product}/stock', [StoreOwnerInventoryController::class, 'updateStock'])->name('products.update-stock');
        Route::put('products/{product}/expiration', [StoreOwnerInventoryController::class, 'updateExpiration'])->name('products.update-expiration');
        Route::post('inventory/audit', [StoreOwnerInventoryController::class, 'audit'])->name('inventory.audit');
        Route::get('reports/lost-opportunities', [StoreOwnerInventoryController::class, 'lostOpportunities'])->name('reports.lost-opportunities');

        // Product CRUD
        Route::apiResource('products', SmProductController::class)->names('products');

        // Store Management (scoped to authenticated owner's default store)
        Route::get('store', [StoreOwnerStoreController::class, 'show'])->name('stores.show');
        Route::put('store', [StoreOwnerStoreController::class, 'update'])->name('stores.update');
        Route::get('store/operating-hours', [SmStoreHoursController::class, 'show']);
        Route::put('store/operating-hours', [SmStoreHoursController::class, 'update']);
    });
});
