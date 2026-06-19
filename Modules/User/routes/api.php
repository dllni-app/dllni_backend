<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\RegisterFcmTokenController;
use App\Http\Middleware\RedirectBrowserDeepLinkApiRequests;
use Modules\User\Http\Controllers\API\DiscoverRestaurantsController;
use Modules\User\Http\Controllers\API\LoginController;
use Modules\User\Http\Controllers\API\LoginVerifyController;
use Modules\User\Http\Controllers\API\MeController;
use Modules\User\Http\Controllers\API\RegisterController;
use Modules\User\Http\Controllers\API\ResendAccountVerificationOtpController;
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
use Modules\User\Http\Controllers\API\RestaurantGroupOrderCancelController;
use Modules\User\Http\Controllers\API\RestaurantGroupOrderItemDestroyController;
use Modules\User\Http\Controllers\API\RestaurantGroupOrderItemStoreController;
use Modules\User\Http\Controllers\API\RestaurantGroupOrderItemUpdateController;
use Modules\User\Http\Controllers\API\RestaurantGroupOrderJoinController;
use Modules\User\Http\Controllers\API\RestaurantGroupOrderMyActiveController;
use Modules\User\Http\Controllers\API\RestaurantGroupOrderPlaceController;
use Modules\User\Http\Controllers\API\RestaurantGroupOrderShowController;
use Modules\User\Http\Controllers\API\RestaurantGroupOrderStoreController;
use Modules\User\Http\Controllers\API\RestaurantGroupOrderSubmitController;
use Modules\User\Http\Controllers\API\RestaurantGroupOrderUnsubmitController;
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
use Modules\User\Http\Controllers\API\UserCleaningOrderCompletionConfirmController;
use Modules\User\Http\Controllers\API\UserCleaningOrderCompletionExtendTimeController;
use Modules\User\Http\Controllers\API\UserCleaningOrderCompletionRejectController;
use Modules\User\Http\Controllers\API\UserCleaningOrderReviewController;
use Modules\User\Http\Controllers\API\UserCleaningOrderEstimatePriceController;
use Modules\User\Http\Controllers\API\UserCleaningOrderEstimateSizeController;
use Modules\User\Http\Controllers\API\UserCleaningOrderSosController;
use Modules\User\Http\Controllers\API\UserCleaningOrderRoomAssignmentsController;
use Modules\User\Http\Controllers\API\UserCleaningOrdersController;
use Modules\User\Http\Controllers\API\UserCleaningOrderShowController;
use Modules\User\Http\Controllers\API\UserCleaningOrderStartVerificationConfirmController;
use Modules\User\Http\Controllers\API\UserCleaningOrderStoreController;
use Modules\User\Http\Controllers\API\UserCleaningOrderUpdateController;
use Modules\User\Http\Controllers\API\UserCleaningBannersController;
use Modules\User\Http\Controllers\API\UserCleaningPreviousWorkersController;
use Modules\User\Http\Controllers\API\UserCouponAvailabilityCheckController;
use Modules\User\Http\Controllers\API\UserMarketingOfferShowController;
use Modules\User\Http\Controllers\API\UserMarketingOffersIndexController;
use Modules\User\Http\Controllers\API\UserNotificationsIndexController;
use Modules\User\Http\Controllers\API\UserNotificationsMarkAllAsReadController;
use Modules\User\Http\Controllers\API\UserNotificationsMarkAsReadController;
use Modules\User\Http\Controllers\API\UserOrderCancelController;
use Modules\User\Http\Controllers\API\UserOrderReorderController;
use Modules\User\Http\Controllers\API\UserOrderScheduleController;
use Modules\User\Http\Controllers\API\UserOrderShowController;
use Modules\User\Http\Controllers\API\UserOrdersIndexController;
use Modules\User\Http\Controllers\API\UserOrderSlotsController;
use Modules\User\Http\Controllers\API\UserOrderTrackingController;
use Modules\User\Http\Controllers\API\UserNormalizeProductTextController;
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
use Modules\User\Http\Controllers\API\UserRestaurantHomeFeaturedOffersController;
use Modules\User\Http\Controllers\API\UserRestaurantHomeLatestOrderedProductsController;
use Modules\User\Http\Controllers\API\UserRestaurantHomeNearestRestaurantsController;
use Modules\User\Http\Controllers\API\UserRestaurantHomeReorderLatestOrderProductsController;
use Modules\User\Http\Controllers\API\UserRestaurantHomeSuggestedProductsController;
use Modules\User\Http\Controllers\API\UserRestaurantMenuSectionsController;
use Modules\User\Http\Controllers\API\UserRestaurantOrderStoreController;
use Modules\User\Http\Controllers\API\UserRestaurantProductsSearchController;
use Modules\User\Http\Controllers\API\UserRestaurantProductsByCategoryController;
use Modules\User\Http\Controllers\API\UserRestaurantProductsWithOffersController;
use Modules\User\Http\Controllers\API\UserSosController;
use Modules\User\Http\Controllers\API\UserSupermarketCartItemDestroyController;
use Modules\User\Http\Controllers\API\UserSupermarketCartItemStoreController;
use Modules\User\Http\Controllers\API\UserSupermarketCartItemUpdateController;
use Modules\User\Http\Controllers\API\UserSupermarketCartShowController;
use Modules\User\Http\Controllers\API\UserSupermarketCheckoutPreviewController;
use Modules\User\Http\Controllers\API\UserSupermarketMasterProductSearchController;
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
    Route::post('verify-account/resend', ResendAccountVerificationOtpController::class);

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
    Route::get('supermarket/stores/{store}', SmStoreShowController::class)
        ->middleware(RedirectBrowserDeepLinkApiRequests::class);
    Route::get('supermarket/products/{product}/similar', SmProductSimilarSearchController::class);
    Route::get('supermarket/products/{product}/compare', SmProductSimilarSearchController::class);
    Route::get('supermarket/products/{product}', SmProductShowController::class)
        ->middleware(RedirectBrowserDeepLinkApiRequests::class);

    Route::prefix('restaurants/home')->group(function (): void {
        Route::get('categories', UserRestaurantHomeCategoriesController::class);
        Route::get('featured-offers', UserRestaurantHomeFeaturedOffersController::class);
        Route::get('exclusive-offers', UserRestaurantHomeExclusiveOffersController::class);
        Route::get('nearest-restaurants', UserRestaurantHomeNearestRestaurantsController::class);
        Route::get('suggested-products', UserRestaurantHomeSuggestedProductsController::class);
    });

    Route::prefix('cleaning/home')->group(function (): void {
        Route::get('banners', UserCleaningBannersController::class);
    });

    Route::get('restaurants/products/with-offers', UserRestaurantProductsWithOffersController::class);
    Route::get('restaurants/products/search', UserRestaurantProductsSearchController::class);
    Route::get('restaurants/products/by-category/{category}', UserRestaurantProductsByCategoryController::class);

    Route::get('restaurants/discover', DiscoverRestaurantsController::class);
    Route::get('restaurants/votes/suggestions', RestaurantGroupVoteSuggestionsController::class);
    Route::get('restaurants/votes/{vote}', RestaurantGroupVoteShowController::class)
        ->whereNumber('vote')
        ->middleware(RedirectBrowserDeepLinkApiRequests::class);
    Route::get('restaurants/coupons', UserRestaurantActiveCouponsController::class);
    Route::get('restaurants/{restaurant}/menu-sections', UserRestaurantMenuSectionsController::class)->whereNumber('restaurant');
    Route::get('restaurants/{restaurant}', UserRestaurantDetailsController::class)
        ->whereNumber('restaurant')
        ->middleware(RedirectBrowserDeepLinkApiRequests::class);
    Route::get('products/{product}', UserProductDetailsController::class)
        ->middleware(RedirectBrowserDeepLinkApiRequests::class);

    Route::get('offers', UserMarketingOffersIndexController::class);
    Route::get('offers/{marketingOffer}', UserMarketingOfferShowController::class);

    Route::middleware(['auth:sanctum'])->group(function (): void {

        Route::post('products/normalize-text', UserNormalizeProductTextController::class);
        Route::get('me', MeController::class);

        Route::post('coupons/check', UserCouponAvailabilityCheckController::class);

        Route::get('account', UserAccountShowController::class);
        Route::patch('account', UserAccountUpdateController::class);
        Route::put('account/password', UserAccountPasswordController::class);

        Route::get('notifications', UserNotificationsIndexController::class);
        Route::patch('notifications/read-all', UserNotificationsMarkAllAsReadController::class);
        Route::patch('notifications/{id}/read', UserNotificationsMarkAsReadController::class);
        Route::put('notifications/token', RegisterFcmTokenController::class);

        Route::post('sos', [UserSosController::class, 'store']);

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
