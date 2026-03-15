<?php

declare(strict_types=1);

use App\Http\Controllers\API\ProductAiController as AppProductAiController;
use Illuminate\Support\Facades\Route;
use Modules\Supermarket\Http\Controllers\API\SmAssistantQueryController;
use Modules\Supermarket\Http\Controllers\API\SmCartController;
use Modules\Supermarket\Http\Controllers\API\SmCartItemController;
use Modules\Supermarket\Http\Controllers\API\SmCategoryController;
use Modules\Supermarket\Http\Controllers\API\SmCommissionRuleController;
use Modules\Supermarket\Http\Controllers\API\SmCouponController;
use Modules\Supermarket\Http\Controllers\API\SmDashboardController;
use Modules\Supermarket\Http\Controllers\API\SmFinancialReportController;
use Modules\Supermarket\Http\Controllers\API\SmInventoryLogController;
use Modules\Supermarket\Http\Controllers\API\SmOfferController;
use Modules\Supermarket\Http\Controllers\API\SmOfferProductController;
use Modules\Supermarket\Http\Controllers\API\SmOrderController;
use Modules\Supermarket\Http\Controllers\API\SmOrderDisputeController;
use Modules\Supermarket\Http\Controllers\API\SmOrderDisputeMessageController;
use Modules\Supermarket\Http\Controllers\API\SmOrderItemController;
use Modules\Supermarket\Http\Controllers\API\SmOrderStatusLogController;
use Modules\Supermarket\Http\Controllers\API\SmPerformanceAnalyticsController;
use Modules\Supermarket\Http\Controllers\API\SmProductController;
use Modules\Supermarket\Http\Controllers\API\SmRecurringOrderController;
use Modules\Supermarket\Http\Controllers\API\SmRecurringOrderItemController;
use Modules\Supermarket\Http\Controllers\API\SmSmartListController;
use Modules\Supermarket\Http\Controllers\API\SmSmartListItemController;
use Modules\Supermarket\Http\Controllers\API\SmStoreController;
use Modules\Supermarket\Http\Controllers\API\SmStoreDailyStatController;
use Modules\Supermarket\Http\Controllers\API\SmStoreDocumentController;
use Modules\Supermarket\Http\Controllers\API\SmStoreHoursController;
use Modules\Supermarket\Http\Controllers\API\SmStoreTrustLogController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\SmOrderStatusController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerDashboardController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerEmployeeIndexController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerEmployeeStatusController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerEmployeeStoreController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerEmployeeUpdateController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerInventoryController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerOfferWeeklySummaryController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerPermissionsController;
use Modules\Supermarket\Http\Controllers\API\StoreOwner\StoreOwnerStoreController;

Route::prefix('v1')->group(function () {
    // Dashboard and Reports
    Route::get('sm-dashboard', [SmDashboardController::class, 'index'])->name('dashboard');
    Route::get('sm-reports/financial', [SmFinancialReportController::class, 'index'])->name('reports.financial');
    Route::get('sm-reports/performance', [SmPerformanceAnalyticsController::class, 'index'])->name('reports.performance');

    Route::apiResource('sm-assistant-queries', SmAssistantQueryController::class)->only(['index', 'show'])->names('sm-assistant-queries');
    Route::apiResource('sm-carts', SmCartController::class)->names('sm-carts');
    Route::apiResource('sm-cart-items', SmCartItemController::class)->names('sm-cart-items');
    Route::apiResource('sm-stores', SmStoreController::class)->names('sm-stores');
    Route::apiResource('sm-store-hours', SmStoreHoursController::class)->names('sm-store-hours');
    Route::apiResource('sm-store-documents', SmStoreDocumentController::class)->names('sm-store-documents');
    Route::apiResource('sm-store-daily-stats', SmStoreDailyStatController::class)->only(['index', 'show'])->names('sm-store-daily-stats');
    Route::apiResource('sm-store-trust-logs', SmStoreTrustLogController::class)->only(['index', 'show'])->names('sm-store-trust-logs');
    Route::apiResource('sm-categories', SmCategoryController::class)->names('sm-categories');
    Route::get('sm-products/available-count', [SmProductController::class, 'availableCount'])->name('sm-products.available-count');
    Route::post('sm-products/import', [SmProductController::class, 'import'])->name('sm-products.import');
    Route::get('sm-products/search', [SmProductController::class, 'index'])->name('sm-products.search');
    Route::apiResource('sm-products', SmProductController::class)->names('sm-products');
    Route::prefix('sm-products/ai')->group(function () {
        Route::post('extract-from-image', [AppProductAiController::class, 'extractFromImage'])->name('sm-products.ai.extract-from-image');
        Route::post('extract-from-menu', [AppProductAiController::class, 'extractFromMenu'])->name('sm-products.ai.extract-from-menu');
        Route::post('generate-image', [AppProductAiController::class, 'generateImage'])->name('sm-products.ai.generate-image');
    });
    Route::apiResource('sm-inventory-logs', SmInventoryLogController::class)->only(['index', 'show'])->names('sm-inventory-logs');
    Route::apiResource('sm-offers', SmOfferController::class)->names('sm-offers');
    Route::apiResource('sm-offer-products', SmOfferProductController::class)->only(['index', 'show'])->names('sm-offer-products');
    Route::apiResource('sm-coupons', SmCouponController::class)->names('sm-coupons');
    Route::apiResource('sm-commission-rules', SmCommissionRuleController::class)->names('sm-commission-rules');
    Route::get('sm-orders/hourly-count', [SmOrderController::class, 'hourlyCount'])->name('sm-orders.hourly-count');
    Route::apiResource('sm-orders', SmOrderController::class)->names('sm-orders');
    Route::apiResource('sm-order-items', SmOrderItemController::class)->only(['index', 'show'])->names('sm-order-items');
    Route::apiResource('sm-order-status-logs', SmOrderStatusLogController::class)->only(['index', 'show'])->names('sm-order-status-logs');
    Route::apiResource('sm-order-disputes', SmOrderDisputeController::class)->names('sm-order-disputes');
    Route::apiResource('sm-order-dispute-messages', SmOrderDisputeMessageController::class)->names('sm-order-dispute-messages');
    Route::apiResource('sm-recurring-orders', SmRecurringOrderController::class)->names('sm-recurring-orders');
    Route::apiResource('sm-recurring-order-items', SmRecurringOrderItemController::class)->only(['index', 'show'])->names('sm-recurring-order-items');
    Route::apiResource('sm-smart-lists', SmSmartListController::class)->names('sm-smart-lists');
    Route::apiResource('sm-smart-list-items', SmSmartListItemController::class)->only(['index', 'show'])->names('sm-smart-list-items');

    // Store Owner Routes
    Route::prefix('store-owner')->name('store-owner.')->group(function () {
        Route::get('dashboard', StoreOwnerDashboardController::class)->name('dashboard');
        Route::get('offers/weekly-summary', StoreOwnerOfferWeeklySummaryController::class)->name('offers.weekly-summary');
        Route::get('permissions', StoreOwnerPermissionsController::class)->name('permissions');

        Route::get('employees', StoreOwnerEmployeeIndexController::class)->name('employees.index');
        Route::post('employees', StoreOwnerEmployeeStoreController::class)->name('employees.store');
        Route::patch('employees/{staff}', StoreOwnerEmployeeUpdateController::class)->name('employees.update');
        Route::patch('employees/{staff}/status', StoreOwnerEmployeeStatusController::class)->name('employees.status');

        // Order Management
        Route::post('orders/{order}/accept', [SmOrderStatusController::class, 'accept'])->name('orders.accept');
        Route::post('orders/{order}/reject', [SmOrderStatusController::class, 'reject'])->name('orders.reject');
        Route::post('orders/{order}/return', [StoreOwnerInventoryController::class, 'processReturn'])->name('orders.return');

        // Inventory Management - Specific routes before wildcards
        Route::get('products/low-stock', [StoreOwnerInventoryController::class, 'lowStock'])->name('products.low-stock');
        Route::put('products/{product}/stock', [StoreOwnerInventoryController::class, 'updateStock'])->name('products.update-stock');
        Route::put('products/{product}/expiration', [StoreOwnerInventoryController::class, 'updateExpiration'])->name('products.update-expiration');
        Route::post('inventory/audit', [StoreOwnerInventoryController::class, 'audit'])->name('inventory.audit');
        Route::get('reports/lost-opportunities', [StoreOwnerInventoryController::class, 'lostOpportunities'])->name('reports.lost-opportunities');

        // Product CRUD
        Route::apiResource('products', SmProductController::class)->names('products');

        // Store Management
        Route::get('stores/{store}', [StoreOwnerStoreController::class, 'show'])->name('stores.show');
        Route::put('stores/{store}', [StoreOwnerStoreController::class, 'update'])->name('stores.update');
    });
});
