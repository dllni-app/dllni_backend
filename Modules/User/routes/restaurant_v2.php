<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\API\RestaurantV2CatalogController;
use Modules\User\Http\Controllers\API\RestaurantV2DiscoveryController;
use Modules\User\Http\Controllers\API\RestaurantV2HomeController;
use Modules\User\Http\Controllers\API\RestaurantV2PickupWindowsController;
use Modules\User\Http\Controllers\API\RestaurantV2ReorderController;
use Modules\User\Http\Controllers\API\RestaurantV2SearchController;
use Modules\User\Http\Middleware\EnsureRestaurantV2Enabled;
use Modules\User\Http\Middleware\SetRestaurantV2CacheHeaders;

Route::middleware([EnsureRestaurantV2Enabled::class, SetRestaurantV2CacheHeaders::class])->group(function (): void {
    Route::middleware('auth:sanctum')->prefix('orders/{orderId}')->whereNumber('orderId')->group(function (): void {
        Route::post('reorder-preview', [RestaurantV2ReorderController::class, 'preview'])->name('orders.reorder-preview');
        Route::post('reorder', [RestaurantV2ReorderController::class, 'apply'])->name('orders.reorder');
    });
    Route::get('home', RestaurantV2HomeController::class)->name('home');
    Route::get('home/{section}', [RestaurantV2HomeController::class, 'section'])
        ->whereIn('section', ['promotions', 'cuisines', 'restaurants', 'dishes'])
        ->name('home.section');
    Route::get('discovery', RestaurantV2DiscoveryController::class)->name('discovery');
    Route::get('search', RestaurantV2SearchController::class)->name('search');
    Route::get('suggestions', [RestaurantV2CatalogController::class, 'suggestions'])->name('suggestions');
    Route::get('cuisines', [RestaurantV2CatalogController::class, 'cuisines'])->name('cuisines');
    Route::get('categories', [RestaurantV2CatalogController::class, 'categories'])->name('categories');
    Route::get('offers', [RestaurantV2CatalogController::class, 'offers'])->name('offers');
    Route::get('products/{product}', [RestaurantV2CatalogController::class, 'product'])->whereNumber('product')->name('products.show');
    Route::get('{restaurant}/pickup-windows', RestaurantV2PickupWindowsController::class)->whereNumber('restaurant')->name('restaurants.pickup-windows');
    Route::get('{restaurant}/menu', [RestaurantV2CatalogController::class, 'menu'])->whereNumber('restaurant')->name('restaurants.menu');
    Route::get('{restaurant}', [RestaurantV2CatalogController::class, 'restaurant'])->whereNumber('restaurant')->name('restaurants.show');
});
