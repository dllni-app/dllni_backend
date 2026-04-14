<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Product;

final class UserRestaurantProductsWithOffersService
{
    public function paginateProductsWithActiveOffers(
        ?int $restaurantId = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $since = CarbonImmutable::now()->subDays(30);

        $query = Product::query()
            ->where('is_available', true)
            ->whereHas('restaurant', fn ($query) => $query->where('is_active', true))
            ->withCount([
                'orderItems as popular_orders_count' => fn ($q) => $q
                    ->whereHas('order', fn ($orderQuery) => $orderQuery
                        ->whereIn('status', [
                            OrderStatus::Completed->value,
                            OrderStatus::PickedUp->value,
                        ])
                        ->where('created_at', '>=', $since)),
            ])
            ->with([
                'offers' => function ($query) {
                    $query->where('is_active', true)
                        ->where(function ($q) {
                            $q->whereNull('ends_at')
                                ->orWhere('ends_at', '>', now());
                        });
                },
                'restaurant' => fn ($q) => $q->select(['id', 'name', 'city', 'district']),
                'category' => fn ($q) => $q->select(['id', 'name']),
                'media',
            ])
            ->whereHas('offers', function ($query) {
                $query->where('is_active', true)
                    ->where(function ($q) {
                        $q->whereNull('ends_at')
                            ->orWhere('ends_at', '>', now());
                    });
            });

        if ($restaurantId !== null) {
            $query->where('restaurant_id', $restaurantId);
        }

        return $query->paginate($perPage);
    }
}
