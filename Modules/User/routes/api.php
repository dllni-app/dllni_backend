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
use Modules\User\Http\Controllers\API\RestaurantCartProductsCountController;
use Modules\User\Http\Controllers\API\RestaurantGroupVoteCastBallotController;
use Modules\User\Http\Controllers\API\RestaurantGroupVoteEndController;
use Modules\User\Http\Controllers\API\RestaurantGroupVoteInviteUsersController;
use Modules\User\Http\Controllers\API\RestaurantGroupVoteMyActiveController;
use Modules\User\Http\Controllers\API\RestaurantGroupVoteShowController;
use Modules\User\Http\Controllers\API\RestaurantGroupVoteStoreController;
use Modules\User\Http\Controllers\API\RestaurantGroupVoteSuggestionsController;
use Modules\User\Http\Controllers\API\RestaurantLuckBoxOptionsController;
use Modules\User\Http\Controllers\API\RestaurantLuckBoxSuggestController;
use Modules\User\Http\Controllers\API\SmHomeFeaturedOffersController;
use Modules\User\Http\Controllers\API\SmHomeNearbyStoresController;
use Modules\User\Http\Controllers\API\SmOrderStatusController;
use Modules\User\Http\Controllers\API\SmProductShowController;
use Modules\User\Http\Controllers\API\SmProductSimilarSearchController;
use Modules\User\Http\Controllers\API\SmProductsSearchController;
use Modules\User\Http\Controllers\API\SmStoreShowController;
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
use Modules\User\Http\Controllers\API\UserCleaningOrderCancelController;
use Modules\User\Http\Controllers\API\UserCleaningOrderEstimatePriceController;
use Modules\User\Http\Controllers\API\UserCleaningOrderEstimateSizeController;
use Modules\User\Http\Controllers\API\UserCleaningOrdersController;
use Modules\User\Http\Controllers\API\UserCleaningOrderShowController;
use Modules\User\Http\Controllers\API\UserCleaningOrderStoreController;
use Modules\User\Http\Controllers\API\UserCleaningOrderUpdateController;
use Modules\User\Http\Controllers\API\UserCleaningPreviousWorkersController;
use Modules\User\Http\Controllers\API\UserCouponAvailabilityCheckController;
use Modules\User\Http\Controllers\API\UserMarketingOfferShowController;
use Modules\User\Http\Controllers\API\UserMarketingOffersIndexController;
use Modules\User\Http\Controllers\API\UserNotificationsIndexController;
use Modules\User\Http\Controllers\API\UserNotificationsMarkAsReadController;
use Modules\User\Http\Controllers\API\UserOrderCancelController;
use Modules\User\Http\Controllers\API\UserOrderReorderController;
use Modules\User\Http\Controllers\API\UserOrderScheduleController;
use Modules\User\Http\Controllers\API\UserOrderShowController;
use Modules\User\Http\Controllers\API\UserOrdersIndexController;
use Modules\User\Http\Controllers\API\UserOrderSlotsController;
use Modules\User\Http\Controllers\API\UserOrderTrackingController;
use Modules\User\Http\Controllers\API\UserProductDetailsController;
use Modules\User\Http\Controllers\API\UserProductFavoriteDestroyController;
use Modules\User\Http\Controllers\API\UserProductFavoritesIndexController;
use Modules\User\Http\Controllers\API\UserProductFavoriteStoreController;
use Modules\User\Http\Controllers\API\UserRestaurantActiveCouponsController;
use Modules\User\Http\Controllers\API\UserRestaurantCartItemDestroyController;
use Modules\User\Http\Controllers\API\UserRestaurantCartItemStoreController;
use Modules\User\Http\Controllers\API\UserRestaurantCartItemUpdateController;
use Modules\User\Http\Controllers\API\UserRestaurantCartShowController;
use Modules\User\Http\Controllers\API\UserRestaurantCheckoutController;
use Modules\User\Http\Controllers\API\UserRestaurantCheckoutPreviewController;
use Modules\User\Http\Controllers\API\UserRestaurantDetailsController;
use Modules\User\Http\Controllers\API\UserRestaurantFavoriteDestroyController;
use Modules\User\Http\Controllers\API\UserRestaurantFavoritesIndexController;
use Modules\User\Http\Controllers\API\UserRestaurantFavoriteStoreController;
use Modules\User\Http\Controllers\API\UserRestaurantHomeCategoriesController;
use Modules\User\Http\Controllers\API\UserRestaurantHomeExclusiveOffersController;
use Modules\User\Http\Controllers\API\UserRestaurantHomeLatestOrderedProductsController;
use Modules\User\Http\Controllers\API\UserRestaurantHomeNearestRestaurantsController;
use Modules\User\Http\Controllers\API\UserRestaurantHomeReorderLatestOrderProductsController;
use Modules\User\Http\Controllers\API\UserRestaurantHomeSuggestedProductsController;
use Modules\User\Http\Controllers\API\UserRestaurantOrderStoreController;
use Modules\User\Http\Controllers\API\UserRestaurantProductsByCategoryController;
use Modules\User\Http\Controllers\API\UserRestaurantProductsWithOffersController;
use Modules\User\Http\Controllers\API\UserSupermarketCartItemDestroyController;
use Modules\User\Http\Controllers\API\UserSupermarketCartItemStoreController;
use Modules\User\Http\Controllers\API\UserSupermarketCartItemUpdateController;
use Modules\User\Http\Controllers\API\UserSupermarketCartShowController;
use Modules\User\Http\Controllers\API\UserSupermarketCheckoutPreviewController;
use Modules\User\Http\Controllers\API\UserSupermarketOrderStoreController;
use Modules\User\Http\Controllers\API\UserSupermarketProductFavoriteDestroyController;
use Modules\User\Http\Controllers\API\UserSupermarketProductFavoritesIndexController;
use Modules\User\Http\Controllers\API\UserSupermarketProductFavoriteStoreController;
use Modules\User\Http\Controllers\API\UserSupermarketShoppingListAddToCartController;
use Modules\User\Http\Controllers\API\UserSupermarketShoppingListDestroyController;
use Modules\User\Http\Controllers\API\UserSupermarketShoppingListIndexController;
use Modules\User\Http\Controllers\API\UserSupermarketShoppingListItemDestroyController;
use Modules\User\Http\Controllers\API\UserSupermarketShoppingListItemStoreController;
use Modules\User\Http\Controllers\API\UserSupermarketShoppingListItemUpdateController;
use Modules\User\Http\Controllers\API\UserSupermarketShoppingListShowController;
use Modules\User\Http\Controllers\API\UserSupermarketShoppingListStoreController;
use Modules\User\Http\Controllers\API\UserSupermarketShoppingListUpdateController;
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
    Route::get('supermarket/products/search', SmProductsSearchController::class);
    Route::get('supermarket/stores/{store}', SmStoreShowController::class);
    Route::get('supermarket/products/{product}/similar', SmProductSimilarSearchController::class);
    Route::get('supermarket/products/{product}/compare', SmProductSimilarSearchController::class);
    Route::get('supermarket/products/{product}', SmProductShowController::class);

    Route::prefix('restaurants/home')->group(function (): void {
        Route::get('categories', UserRestaurantHomeCategoriesController::class);
        Route::get('exclusive-offers', UserRestaurantHomeExclusiveOffersController::class);
        Route::get('nearest-restaurants', UserRestaurantHomeNearestRestaurantsController::class);
        Route::get('suggested-products', UserRestaurantHomeSuggestedProductsController::class);
    });

    Route::get('restaurants/products/with-offers', UserRestaurantProductsWithOffersController::class);
    Route::get('restaurants/products/by-category/{category}', UserRestaurantProductsByCategoryController::class);

    Route::get('restaurants/discover', DiscoverRestaurantsController::class);
    Route::get('restaurants/votes/suggestions', RestaurantGroupVoteSuggestionsController::class);
    Route::get('restaurants/votes/{vote}', RestaurantGroupVoteShowController::class)->whereNumber('vote');
    Route::get('restaurants/coupons', UserRestaurantActiveCouponsController::class);
    Route::get('restaurants/{restaurant}', UserRestaurantDetailsController::class)->whereNumber('restaurant');
    Route::get('products/{product}', UserProductDetailsController::class);

    Route::get('offers', UserMarketingOffersIndexController::class);
    Route::get('offers/{marketingOffer}', UserMarketingOfferShowController::class);

    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('me', MeController::class);

        Route::post('coupons/check', UserCouponAvailabilityCheckController::class);

        Route::get('account', UserAccountShowController::class);
        Route::patch('account', UserAccountUpdateController::class);
        Route::put('account/password', UserAccountPasswordController::class);

        Route::get('notifications', UserNotificationsIndexController::class);
        Route::patch('notifications/{id}/read', UserNotificationsMarkAsReadController::class);

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

        Route::get('favorites/supermarket/products', UserSupermarketProductFavoritesIndexController::class);
        Route::post('favorites/supermarket/products/{product}', UserSupermarketProductFavoriteStoreController::class);
        Route::delete('favorites/supermarket/products/{product}', UserSupermarketProductFavoriteDestroyController::class);

        Route::get('favorites/products', UserProductFavoritesIndexController::class);
        Route::post('favorites/products/{product}', UserProductFavoriteStoreController::class);
        Route::delete('favorites/products/{product}', UserProductFavoriteDestroyController::class);

        Route::get('cleaning/orders', UserCleaningOrdersController::class);
        Route::post('cleaning/orders', UserCleaningOrderStoreController::class);
        Route::post('cleaning/orders/estimate-size', UserCleaningOrderEstimateSizeController::class);
        Route::get('cleaning/orders/previous-workers', UserCleaningPreviousWorkersController::class);
        Route::post('cleaning/orders/estimate-price', UserCleaningOrderEstimatePriceController::class);
        Route::get('cleaning/orders/{order}', UserCleaningOrderShowController::class);
        Route::patch('cleaning/orders/{order}', UserCleaningOrderUpdateController::class);
        Route::post('cleaning/orders/{order}/cancel', UserCleaningOrderCancelController::class);

        Route::get('orders', UserOrdersIndexController::class);
        Route::get('orders/slots', UserOrderSlotsController::class);
        Route::get('orders/{section}/{orderId}', UserOrderShowController::class);
        Route::get('orders/{section}/{orderId}/tracking', UserOrderTrackingController::class);
        Route::post('orders/{section}/{orderId}/cancel', UserOrderCancelController::class);
        Route::post('orders/{section}/{orderId}/reorder', UserOrderReorderController::class);
        Route::patch('orders/{section}/{orderId}/schedule', UserOrderScheduleController::class);

        Route::get('restaurants/cart', UserRestaurantCartShowController::class);
        Route::post('restaurants/cart/items', UserRestaurantCartItemStoreController::class);
        Route::patch('restaurants/cart/items/{itemId}', UserRestaurantCartItemUpdateController::class);
        Route::delete('restaurants/cart/items/{itemId}', UserRestaurantCartItemDestroyController::class);
        Route::get('restaurants/cart/products-count', RestaurantCartProductsCountController::class);
        Route::post('restaurants/checkout', UserRestaurantCheckoutController::class);
        Route::post('restaurants/checkout/preview', UserRestaurantCheckoutPreviewController::class);
        Route::post('restaurants/orders', UserRestaurantOrderStoreController::class);

        Route::get('supermarket/cart', UserSupermarketCartShowController::class);
        Route::post('supermarket/cart/items', UserSupermarketCartItemStoreController::class);
        Route::patch('supermarket/cart/items/{itemId}', UserSupermarketCartItemUpdateController::class);
        Route::delete('supermarket/cart/items/{itemId}', UserSupermarketCartItemDestroyController::class);
        Route::get('supermarket/shopping-lists', UserSupermarketShoppingListIndexController::class);
        Route::post('supermarket/shopping-lists', UserSupermarketShoppingListStoreController::class);
        Route::get('supermarket/shopping-lists/{shoppingList}', UserSupermarketShoppingListShowController::class)->whereNumber('shoppingList');
        Route::patch('supermarket/shopping-lists/{shoppingList}', UserSupermarketShoppingListUpdateController::class)->whereNumber('shoppingList');
        Route::delete('supermarket/shopping-lists/{shoppingList}', UserSupermarketShoppingListDestroyController::class)->whereNumber('shoppingList');
        Route::post('supermarket/shopping-lists/{shoppingList}/add-to-cart', UserSupermarketShoppingListAddToCartController::class)->whereNumber('shoppingList');
        Route::post('supermarket/shopping-lists/{shoppingList}/items', UserSupermarketShoppingListItemStoreController::class)->whereNumber('shoppingList');
        Route::patch('supermarket/shopping-lists/{shoppingList}/items/{item}', UserSupermarketShoppingListItemUpdateController::class)->whereNumber(['shoppingList', 'item']);
        Route::delete('supermarket/shopping-lists/{shoppingList}/items/{item}', UserSupermarketShoppingListItemDestroyController::class)->whereNumber(['shoppingList', 'item']);
        Route::post('supermarket/checkout/preview', UserSupermarketCheckoutPreviewController::class);
        Route::post('supermarket/orders', UserSupermarketOrderStoreController::class);
        Route::get('supermarket/orders/{order}/status', SmOrderStatusController::class)->whereNumber('order');

        Route::get('restaurants/luck-box/options', RestaurantLuckBoxOptionsController::class);
        Route::post('restaurants/luck-box/suggest', RestaurantLuckBoxSuggestController::class);
        Route::get('restaurants/home/latest-ordered-products', UserRestaurantHomeLatestOrderedProductsController::class);
        Route::post('restaurants/home/latest-ordered-products/reorder', UserRestaurantHomeReorderLatestOrderProductsController::class);

        Route::post('restaurants/votes', RestaurantGroupVoteStoreController::class);
        Route::get('restaurants/votes/active', RestaurantGroupVoteMyActiveController::class);
        Route::post('restaurants/votes/{vote}/invite', RestaurantGroupVoteInviteUsersController::class)->whereNumber('vote');
        Route::post('restaurants/votes/{vote}/ballots', RestaurantGroupVoteCastBallotController::class)->whereNumber('vote');
        Route::post('restaurants/votes/{vote}/end', RestaurantGroupVoteEndController::class)->whereNumber('vote');
    });
});
