<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Resturants\Models\Product;
use Modules\User\Http\Requests\RestaurantHomeSuggestedProductsRequest;

final class UserRestaurantSuggestedProductsService
{
    /**
     * @return Collection<int, Product>
     */
    public function suggestedForHome(RestaurantHomeSuggestedProductsRequest $request): Collection
    {
        $limit = $request->integer('limit', 15);

        return Product::query()
            ->select('products.*')
            ->join('restaurants', 'restaurants.id', '=', 'products.restaurant_id')
            ->where('products.is_available', true)
            ->where('restaurants.is_active', true)
            ->with([
                'media',
                'category',
                'restaurant.media',
                'restaurant.cuisineTypes',
            ])
            ->orderByDesc('products.is_featured')
            ->orderByDesc('restaurants.average_rating')
            ->orderByDesc('restaurants.is_featured')
            ->orderByDesc('products.id')
            ->limit($limit)
            ->get();
    }
}
