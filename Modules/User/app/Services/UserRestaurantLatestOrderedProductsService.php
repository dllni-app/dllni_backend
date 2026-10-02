<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Favorite;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\Product;
use Modules\User\Http\Requests\RestaurantHomeLatestOrderedProductsRequest;

final class UserRestaurantLatestOrderedProductsService
{
    /**
     * Return products from the user's latest non-cancelled restaurant order only.
     *
     * @return Collection<int, OrderItem>
     */
    public function latestOrderedItems(User $user, RestaurantHomeLatestOrderedProductsRequest $request): Collection
    {
        $limit = $request->integer('limit', 15);

        $latestOrder = Order::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->with([
                'orderItems.product.media',
                'orderItems.product.restaurant',
                'orderItems.order',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latestOrder === null) {
            return new Collection;
        }

        $items = $latestOrder->orderItems
            ->filter(function (OrderItem $item): bool {
                return $item->product !== null
                    && $item->product->is_available
                    && $item->product->restaurant !== null
                    && $item->product->restaurant->is_active;
            })
            ->sortByDesc('id')
            ->unique('product_id')
            ->take($limit)
            ->values();

        $this->attachFavoriteFlagsToProducts($items, $user);

        return new Collection($items->all());
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     */
    private function attachFavoriteFlagsToProducts(Collection $items, User $user): void
    {
        $products = $items
            ->map(fn (OrderItem $i) => $i->product)
            ->filter()
            ->values();

        if ($products->isEmpty()) {
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
