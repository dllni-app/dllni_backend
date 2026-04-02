<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Modules\Resturants\Models\Favorite;
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

        $products = Product::query()
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

        $this->attachFavoriteFlags($products, $request->user('sanctum'));

        return $products;
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function attachFavoriteFlags(Collection $products, ?User $user): void
    {
        if ($user === null || $products->isEmpty()) {
            $products->each(fn (Product $p) => $p->setAttribute('isFavoritedByUser', false));

            return;
        }

        $ids = $products->modelKeys();

        $favoritedIds = Favorite::query()
            ->where('user_id', $user->id)
            ->where('favorable_type', Product::class)
            ->whereIn('favorable_id', $ids)
            ->pluck('favorable_id')
            ->flip();

        $products->each(function (Product $p) use ($favoritedIds): void {
            $p->setAttribute('isFavoritedByUser', $favoritedIds->has($p->id));
        });
    }
}
