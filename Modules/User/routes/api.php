<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Supermarket\Http\Controllers\API\SmCartItemController;
use Modules\User\Http\Controllers\API\DiscoverRestaurantsController;
use Modules\User\Http\Controllers\API\LoginController;
use Modules\User\Http\Controllers\API\LoginVerifyController;
use Modules\User\Http\Controllers\API\MeController;
use Modules\User\Http\Controllers\API\RegisterController;
use Modules\User\Http\Controllers\API\ResetPasswordConfirmController;
use Modules\User\Http\Controllers\API\ResetPasswordController;
use Modules\User\Http\Controllers\API\RestaurantCartAddItemController;
use Modules\User\Http\Controllers\API\RestaurantCheckoutController;
use Modules\User\Http\Controllers\API\RestaurantGroupVoteCastBallotController;
use Modules\User\Http\Controllers\API\RestaurantGroupVoteEndController;
use Modules\User\Http\Controllers\API\RestaurantGroupVoteShowController;
use Modules\User\Http\Controllers\API\RestaurantGroupVoteStoreController;
use Modules\User\Http\Controllers\API\RestaurantGroupVoteSuggestionsController;
use Modules\User\Http\Controllers\API\SmHomeFeaturedOffersController;
use Modules\User\Http\Controllers\API\SmHomeNearbyStoresController;
use Modules\User\Http\Controllers\API\SmLuckBoxOptionsController;
use Modules\User\Http\Controllers\API\SmLuckBoxSuggestController;
use Modules\User\Http\Controllers\API\SmOrderStatusController;
use Modules\User\Http\Controllers\API\SmStoresIndexController;
use Modules\User\Http\Controllers\API\UserAccountPasswordController;
use Modules\User\Http\Controllers\API\UserAccountShowController;
use Modules\User\Http\Controllers\API\UserAccountUpdateController;
use Modules\User\Http\Controllers\API\UserAddressDestroyController;
use Modules\User\Http\Controllers\API\UserAddressIndexController;
use Modules\User\Http\Controllers\API\UserAddressPatchController;
use Modules\User\Http\Controllers\API\UserAddressSetDefaultController;
use Modules\User\Http\Controllers\API\UserAddressShowController;
use Modules\User\Http\Controllers\API\UserAddressStoreController;
use Modules\User\Http\Controllers\API\UserAddressUpdateController;
use Modules\User\Http\Controllers\API\UserMarketingOfferShowController;
use Modules\User\Http\Controllers\API\UserMarketingOffersIndexController;
use Modules\User\Http\Controllers\API\UserNotificationsIndexController;
use Modules\User\Http\Controllers\API\UserProductDetailsController;
use Modules\User\Http\Controllers\API\UserProductFavoriteDestroyController;
use Modules\User\Http\Controllers\API\UserProductFavoritesIndexController;
use Modules\User\Http\Controllers\API\UserProductFavoriteStoreController;
use Modules\User\Http\Controllers\API\UserRestaurantDetailsController;
use Modules\User\Http\Controllers\API\UserRestaurantFavoriteDestroyController;
use Modules\User\Http\Controllers\API\UserRestaurantFavoritesIndexController;
use Modules\User\Http\Controllers\API\UserRestaurantFavoriteStoreController;
use Modules\User\Http\Controllers\API\UserRestaurantHomeCategoriesController;
use Modules\User\Http\Controllers\API\UserRestaurantHomeExclusiveOffersController;
use Modules\User\Http\Controllers\API\UserRestaurantHomeLatestOrderedProductsController;
use Modules\User\Http\Controllers\API\UserRestaurantHomeNearestRestaurantsController;
use Modules\User\Http\Controllers\API\UserRestaurantHomeSuggestedProductsController;
use Modules\User\Http\Controllers\API\UserRestaurantOrdersController;
use Modules\User\Http\Controllers\API\UserRestaurantOrderShowController;
use Modules\User\Http\Controllers\API\UserSupermarketStoreFavoriteDestroyController;
use Modules\User\Http\Controllers\API\UserSupermarketStoreFavoritesIndexController;
use Modules\User\Http\Controllers\API\UserSupermarketStoreFavoriteStoreController;
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

    Route::get('supermarket/luck-box/options', SmLuckBoxOptionsController::class);
    Route::post('supermarket/luck-box/suggest', SmLuckBoxSuggestController::class);

    Route::prefix('restaurants/home')->group(function (): void {
        Route::get('categories', UserRestaurantHomeCategoriesController::class);
        Route::get('exclusive-offers', UserRestaurantHomeExclusiveOffersController::class);
        Route::get('nearest-restaurants', UserRestaurantHomeNearestRestaurantsController::class);
        Route::get('suggested-products', UserRestaurantHomeSuggestedProductsController::class);
    });

    Route::get('restaurants/discover', DiscoverRestaurantsController::class);
    Route::get('restaurants/votes/suggestions', RestaurantGroupVoteSuggestionsController::class);
    Route::get('restaurants/votes/{vote}', RestaurantGroupVoteShowController::class);
    Route::get('restaurants/{restaurant}', UserRestaurantDetailsController::class);
    Route::get('products/{product}', UserProductDetailsController::class);

    Route::get('offers', UserMarketingOffersIndexController::class);
    Route::get('offers/{marketingOffer}', UserMarketingOfferShowController::class);

    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('me', MeController::class);

        Route::get('account', UserAccountShowController::class);
        Route::patch('account', UserAccountUpdateController::class);
        Route::put('account/password', UserAccountPasswordController::class);

        Route::get('notifications', UserNotificationsIndexController::class);

        Route::get('addresses', UserAddressIndexController::class);
        Route::post('addresses', UserAddressStoreController::class);
        Route::get('addresses/{userAddress}', UserAddressShowController::class);
        Route::patch('addresses/{userAddress}', UserAddressPatchController::class);
        Route::put('addresses/{userAddress}', UserAddressUpdateController::class);
        Route::patch('addresses/{userAddress}/set-default', UserAddressSetDefaultController::class);
        Route::delete('addresses/{userAddress}', UserAddressDestroyController::class);

        Route::get('favorites/restaurants', UserRestaurantFavoritesIndexController::class);
        Route::post('favorites/restaurants/{restaurant}', UserRestaurantFavoriteStoreController::class);
        Route::delete('favorites/restaurants/{restaurant}', UserRestaurantFavoriteDestroyController::class);

        Route::get('favorites/supermarket/stores', UserSupermarketStoreFavoritesIndexController::class);
        Route::post('favorites/supermarket/stores/{store}', UserSupermarketStoreFavoriteStoreController::class);
        Route::delete('favorites/supermarket/stores/{store}', UserSupermarketStoreFavoriteDestroyController::class);

        Route::get('favorites/products', UserProductFavoritesIndexController::class);
        Route::post('favorites/products/{product}', UserProductFavoriteStoreController::class);
        Route::delete('favorites/products/{product}', UserProductFavoriteDestroyController::class);

        Route::prefix('supermarket')->group(function (): void {
            Route::apiResource('items', SmCartItemController::class)->parameters(['items' => 'sm_cart_item']);
        });

        Route::get('supermarket/orders/{order}/status', SmOrderStatusController::class);

        Route::post('restaurants/cart/items', RestaurantCartAddItemController::class);
        Route::post('restaurants/checkout', RestaurantCheckoutController::class);
        Route::get('restaurants/orders', UserRestaurantOrdersController::class);
        Route::get('restaurants/orders/{order}', UserRestaurantOrderShowController::class);
        Route::get('restaurants/home/latest-ordered-products', UserRestaurantHomeLatestOrderedProductsController::class);

        Route::post('restaurants/votes', RestaurantGroupVoteStoreController::class);
        Route::post('restaurants/votes/{vote}/ballots', RestaurantGroupVoteCastBallotController::class);
        Route::post('restaurants/votes/{vote}/end', RestaurantGroupVoteEndController::class);
    });
});
