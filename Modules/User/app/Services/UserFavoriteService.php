<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Resturants\Models\Favorite;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;

final class UserFavoriteService
{
    public function addRestaurantFavorite(User $user, Restaurant $restaurant): Favorite
    {
        return Favorite::firstOrCreate([
            'user_id' => $user->id,
            'favorable_type' => Restaurant::class,
            'favorable_id' => $restaurant->id,
        ]);
    }

    public function removeRestaurantFavorite(User $user, Restaurant $restaurant): void
    {
        Favorite::query()
            ->where('user_id', $user->id)
            ->where('favorable_type', Restaurant::class)
            ->where('favorable_id', $restaurant->id)
            ->delete();
    }

    /**
     * @return LengthAwarePaginator<int, Restaurant>
     */
    public function paginateFavoriteRestaurants(User $user, int $perPage): LengthAwarePaginator
    {
        return Restaurant::query()
            ->select('restaurants.*')
            ->join('favorites', function ($join) use ($user): void {
                $join->on('restaurants.id', '=', 'favorites.favorable_id')
                    ->where('favorites.favorable_type', '=', Restaurant::class)
                    ->where('favorites.user_id', '=', $user->id);
            })
            ->orderByDesc('favorites.created_at')
            ->with(['media', 'cuisineTypes', 'primaryActiveOffer'])
            ->paginate($perPage);
    }

    public function addSupermarketStoreFavorite(User $user, SmStore $store): Favorite
    {
        return Favorite::firstOrCreate([
            'user_id' => $user->id,
            'favorable_type' => SmStore::class,
            'favorable_id' => $store->id,
        ]);
    }

    public function removeSupermarketStoreFavorite(User $user, SmStore $store): void
    {
        Favorite::query()
            ->where('user_id', $user->id)
            ->where('favorable_type', SmStore::class)
            ->where('favorable_id', $store->id)
            ->delete();
    }

    /**
     * @return LengthAwarePaginator<int, SmStore>
     */
    public function paginateFavoriteSupermarketStores(User $user, int $perPage): LengthAwarePaginator
    {
        return SmStore::query()
            ->select('sm_stores.*')
            ->join('favorites', function ($join) use ($user): void {
                $join->on('sm_stores.id', '=', 'favorites.favorable_id')
                    ->where('favorites.favorable_type', '=', SmStore::class)
                    ->where('favorites.user_id', '=', $user->id);
            })
            ->orderByDesc('favorites.created_at')
            ->with('owner', 'highestDiscountOffer')
            ->paginate($perPage)
            ->through(function (SmStore $store): SmStore {
                $store->setAttribute('isFavoritedByUser', true);

                return $store;
            });
    }

    public function addProductFavorite(User $user, Product $product): Favorite
    {
        return Favorite::firstOrCreate([
            'user_id' => $user->id,
            'favorable_type' => Product::class,
            'favorable_id' => $product->id,
        ]);
    }

    public function addSupermarketProductFavorite(User $user, SmProduct $product): Favorite
    {
        $favoriteType = $product->getMorphClass();

        return Favorite::firstOrCreate([
            'user_id' => $user->id,
            'favorable_type' => $favoriteType,
            'favorable_id' => $product->id,
        ]);
    }

    public function removeSupermarketProductFavorite(User $user, SmProduct $product): void
    {
        $favoriteType = $product->getMorphClass();

        Favorite::query()
            ->where('user_id', $user->id)
            ->where('favorable_type', $favoriteType)
            ->where('favorable_id', $product->id)
            ->delete();
    }

    public function removeProductFavorite(User $user, Product $product): void
    {
        Favorite::query()
            ->where('user_id', $user->id)
            ->where('favorable_type', Product::class)
            ->where('favorable_id', $product->id)
            ->delete();
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginateFavoriteProducts(User $user, int $perPage): LengthAwarePaginator
    {
        $paginator = Product::query()
            ->select('products.*')
            ->join('favorites', function ($join) use ($user): void {
                $join->on('products.id', '=', 'favorites.favorable_id')
                    ->where('favorites.favorable_type', '=', Product::class)
                    ->where('favorites.user_id', '=', $user->id);
            })
            ->join('restaurants', 'restaurants.id', '=', 'products.restaurant_id')
            ->where('products.is_available', true)
            ->where('restaurants.is_active', true)
            ->orderByDesc('favorites.created_at')
            ->with([
                'media',
                'category',
                'restaurant.media',
                'restaurant.cuisineTypes',
                'offers' => function ($query) {
                    $query->where('is_active', true)
                        ->where(function ($q) {
                            $q->whereNull('ends_at')
                                ->orWhere('ends_at', '>', now());
                        });
                },
            ])
            ->paginate($perPage);

        $paginator->getCollection()->each(function (Product $product): void {
            $product->setAttribute('isFavoritedByUser', true);
        });

        return $paginator;
    }
}
