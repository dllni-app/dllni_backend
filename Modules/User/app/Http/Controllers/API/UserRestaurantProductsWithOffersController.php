<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Models\CartItem;
use Modules\User\Http\Requests\RestaurantProductsWithOffersRequest;
use Modules\User\Http\Resources\UserRestaurantProductWithOffersResource;
use Modules\User\Services\UserRestaurantProductsWithOffersService;

final class UserRestaurantProductsWithOffersController
{
    public function __construct(
        private UserRestaurantProductsWithOffersService $service,
    ) {}

    public function __invoke(RestaurantProductsWithOffersRequest $request): JsonResponse
    {
        $products = $this->service->paginateProductsWithActiveOffers(
            restaurantId: $request->getRestaurantId(),
            perPage: $request->getPerPage(),
        );

        $user = $request->user('sanctum');
        if ($user !== null) {
            $productIds = $products->getCollection()
                ->pluck('id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->values();

            $cartQuantities = $productIds->isEmpty()
                ? collect()
                : CartItem::query()
                    ->whereIn('product_id', $productIds->all())
                    ->whereHas('cart', fn ($query) => $query->where('user_id', $user->id))
                    ->select('product_id', DB::raw('SUM(quantity) as cart_quantity'))
                    ->groupBy('product_id')
                    ->pluck('cart_quantity', 'product_id');

            $products->getCollection()->each(function ($product) use ($user, $cartQuantities): void {
                $product->setAttribute('isFavoritedByUser', $user->favorites()
                    ->where('favorable_type', 'Modules\\Resturants\\Models\\Product')
                    ->where('favorable_id', $product->id)
                    ->exists());

                $product->setAttribute('cartQuantity', (int) ($cartQuantities[(int) $product->id] ?? 0));
            });
        } else {
            $products->getCollection()->each(function ($product): void {
                $product->setAttribute('cartQuantity', 0);
            });
        }

        $response = UserRestaurantProductWithOffersResource::collection($products)->response();
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRESERVE_ZERO_FRACTION);

        return $response;
    }
}
