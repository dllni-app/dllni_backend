<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Favorite;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\Product;
use Modules\User\Http\Requests\RestaurantHomeLatestOrderedProductsRequest;

final class UserRestaurantLatestOrderedProductsService
{
    private const int FETCH_CAP = 120;

    /**
     * @return Collection<int, OrderItem>
     */
    public function latestOrderedItems(User $user, RestaurantHomeLatestOrderedProductsRequest $request): Collection
    {
        $limit = $request->integer('limit', 15);

        $items = OrderItem::query()
            ->select('order_items.*')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.user_id', $user->id)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereHas('product', function ($q): void {
                $q->where('is_available', true)
                    ->whereHas('restaurant', fn ($r) => $r->where('is_active', true));
            })
            ->with([
                'product.media',
                'product.restaurant',
                'order',
            ])
            ->orderByDesc('orders.created_at')
            ->orderByDesc('orders.id')
            ->orderByDesc('order_items.id')
            ->limit(self::FETCH_CAP)
            ->get();

        $deduped = new Collection;
        $seenProductIds = [];

        foreach ($items as $item) {
            if ($item->product === null) {
                continue;
            }

            if (isset($seenProductIds[$item->product_id])) {
                continue;
            }

            $seenProductIds[$item->product_id] = true;
            $deduped->push($item);

            if ($deduped->count() >= $limit) {
                break;
            }
        }

        $this->attachFavoriteFlagsToProducts($deduped, $user);

        return $deduped;
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
